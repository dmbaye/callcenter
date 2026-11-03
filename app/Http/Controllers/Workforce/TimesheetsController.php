<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TimeAdjustment;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Workforce\Planner;
use App\Services\Workforce\Timesheets;
use App\Support\SimpleXlsx;
use App\Support\Workforce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Weekly timesheets: an agent sees and corrects their own; supervisors see
 * the team, open an agent's week, validate it and export for payroll.
 */
class TimesheetsController extends Controller
{
    use WorkforceAccess;

    public function __construct(private Planner $planner, private Timesheets $timesheets) {}

    public function index(Request $request, Team $currentTeam): Response
    {
        $user = $request->user();
        $shell = $this->shell($request, $currentTeam, $this->planner);
        $supervisor = $this->isSupervisor($user, $currentTeam);
        $weekStart = Workforce::weekStart($this->anchorDate($request, 'week'));

        // An agent only ever sees their own sheet.
        $agent = $user;

        if ($supervisor && $request->filled('user')) {
            $agent = $currentTeam->members()->where('users.id', $request->integer('user'))->firstOrFail();
            abort_unless($agent->is($user) || $this->planner->canManage($currentTeam, $user, $agent), 403);
        }

        $showTeam = $supervisor && ! $request->filled('user');

        return Inertia::render('workforce/Timesheets', [
            ...$shell,
            'weekStart' => $weekStart->toDateString(),
            'previous' => $weekStart->subWeek()->toDateString(),
            'next' => $weekStart->addWeek()->toDateString(),
            'agent' => ['id' => $agent->id, 'name' => $agent->name, 'self' => $agent->is($user)],
            'canValidate' => ! $showTeam && $this->planner->canManage($currentTeam, $user, $agent),
            'team' => $showTeam ? collect($this->timesheets->teamWeek($currentTeam, $weekStart, $this->planner->managedIds($currentTeam, $user)))
                ->map(fn (array $row) => ['user' => $row['user'], 'totals' => $row['totals'], 'approval' => $row['approval']])->all() : null,
            'sheet' => $showTeam ? null : $this->timesheets->sheet($currentTeam, $agent, $weekStart),
            'adjustments' => $showTeam ? [] : TimeAdjustment::query()->with('reviewer:id,name')
                ->where('team_id', $currentTeam->id)->where('user_id', $agent->id)
                ->latest()->limit(20)->get()->map(fn (TimeAdjustment $a) => $a->toCard())->all(),
        ]);
    }

    public function adjust(Request $request, Team $currentTeam): RedirectResponse
    {
        $data = $request->validate([
            'time_entry_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'clock_in' => ['required', 'date_format:H:i'],
            'clock_out' => ['required', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $entry = isset($data['time_entry_id'])
            ? TimeEntry::query()->where('team_id', $currentTeam->id)->findOrFail($data['time_entry_id'])
            : null;

        $this->planner->ensureSetup($currentTeam);
        $this->timesheets->requestAdjustment($currentTeam, $request->user(), $entry, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Correction sent to your supervisor.')]);

        return back();
    }

    public function approve(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->ensureSupervisor($request, $currentTeam);
        [$agent, $week] = $this->agentWeek($request, $currentTeam);

        $this->timesheets->approveWeek($currentTeam, $agent, $week, $request->user());

        return back();
    }

    public function reopen(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->ensureSupervisor($request, $currentTeam);
        [$agent, $week] = $this->agentWeek($request, $currentTeam);

        $this->timesheets->reopenWeek($currentTeam, $agent, $week);

        return back();
    }

    /** CSV (Excel-friendly), .xlsx, or a print page to save as PDF. */
    public function export(Request $request, Team $currentTeam): HttpResponse|StreamedResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'week' => ['required', 'date_format:Y-m-d'],
            'format' => ['required', Rule::in(['csv', 'xlsx', 'print'])],
            'user' => ['nullable', 'integer'],
        ]);

        $supervisor = $this->isSupervisor($user, $currentTeam);
        $only = null;

        if (! $supervisor) {
            $only = $user;
        } elseif (! empty($data['user'])) {
            $only = $currentTeam->members()->where('users.id', $data['user'])->firstOrFail();
            abort_unless($only->is($user) || $this->planner->canManage($currentTeam, $user, $only), 403);
        }

        $this->planner->ensureSetup($currentTeam);
        $week = Workforce::weekStart($data['week']);
        [$header, $rows] = $this->timesheets->exportRows($currentTeam, $week, $only, $this->planner->managedIds($currentTeam, $user));
        $name = 'feuille-de-temps-'.$week->toDateString().($only ? '-'.str($only->name)->slug() : '');

        if ($data['format'] === 'xlsx') {
            return response(SimpleXlsx::build(__('Week of :date', ['date' => $week->format('d-m-Y')]), $header, $rows), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"',
            ]);
        }

        if ($data['format'] === 'print') {
            return response()->view('workforce.timesheet-print', [
                'team' => $currentTeam,
                'week' => $week,
                'header' => $header,
                'rows' => $rows,
                'agent' => $only,
            ]);
        }

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }

            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: User, 1: \Carbon\CarbonImmutable} */
    private function agentWeek(Request $request, Team $team): array
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'week' => ['required', 'date_format:Y-m-d'],
        ]);

        $agent = $team->members()->where('users.id', $data['user_id'])->firstOrFail();
        abort_unless($this->planner->canManage($team, $request->user(), $agent), 403);

        return [$agent, Workforce::weekStart($data['week'])];
    }
}
