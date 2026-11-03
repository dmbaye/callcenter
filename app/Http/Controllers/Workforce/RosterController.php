<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\ShiftAssignment;
use App\Models\ShiftTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\Workforce\Planner;
use App\Support\Workforce;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supervisors' roster: agents × days (week or month), shifts assigned by
 * click, coverage per shift against the minimum staffing, leave shown in
 * the grid, copy of the previous week and « tell the team ».
 */
class RosterController extends Controller
{
    use WorkforceAccess;

    public function __construct(private Planner $planner) {}

    /** /planning: the roster for supervisors, the personal schedule for agents. */
    public function home(Request $request, Team $currentTeam): RedirectResponse
    {
        return $this->isSupervisor($request->user(), $currentTeam)
            ? to_route('workforce.roster', ['current_team' => $currentTeam->slug])
            : to_route('workforce.me', ['current_team' => $currentTeam->slug]);
    }

    public function index(Request $request, Team $currentTeam): Response
    {
        $this->ensureSupervisor($request, $currentTeam);
        $shell = $this->shell($request, $currentTeam, $this->planner);

        $view = $request->query('view') === 'month' ? 'month' : 'week';
        $anchor = $this->anchorDate($request);
        [$from, $to] = $view === 'month'
            ? [$anchor->startOfMonth(), $anchor->endOfMonth()->startOfDay()]
            : [Workforce::weekStart($anchor), Workforce::weekStart($anchor)->addDays(6)];

        $assignments = $currentTeam->shiftAssignments()->with('template')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('starts_at')->get();

        $leaves = $currentTeam->leaveRequests()->with('type')
            ->whereIn('status', [LeaveRequest::APPROVED, LeaveRequest::PENDING])
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        $settings = $currentTeam->workforceSetting;

        // A supervisor with assigned agents sees them first; « all » shows the whole team.
        $viewer = $request->user();
        $hasOwnAgents = collect($this->planner->memberships($currentTeam))->contains(fn (array $m) => $m['supervisor_id'] === $viewer->id);
        $scope = $request->query('scope') ?? ($hasOwnAgents ? 'mine' : 'all');
        $scope = $scope === 'mine' && $hasOwnAgents ? 'mine' : 'all';
        $agents = $this->planner->agents($currentTeam)
            ->when($scope === 'mine', fn ($all) => $all->filter(fn (User $u) => $this->planner->supervisorOf($currentTeam, $u) === $viewer->id));
        $supervisorNames = $this->planner->supervisors($currentTeam)->pluck('name', 'id')->all();

        return Inertia::render('workforce/Roster', [
            ...$shell,
            'view' => $view,
            'anchor' => $anchor->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous' => ($view === 'month' ? $from->subMonth() : $from->subWeek())->toDateString(),
            'next' => ($view === 'month' ? $from->addMonth() : $from->addWeek())->toDateString(),
            'days' => $this->dayList($from, $to),
            'scope' => $scope,
            'hasOwnAgents' => $hasOwnAgents,
            'agents' => $agents->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'jobTitle' => $user->job_title,
                'avatar' => $user->avatar,
                'supervisor' => $supervisorNames[$this->planner->supervisorOf($currentTeam, $user) ?? 0] ?? null,
                'managed' => $this->planner->canManage($currentTeam, $request->user(), $user),
            ])->values()->all(),
            'templates' => $currentTeam->shiftTemplates()->get()->map(fn (ShiftTemplate $t) => $t->toCard())->all(),
            // [userId][date] => shifts
            'assignments' => $assignments->groupBy('user_id')->map(
                fn ($rows) => $rows->groupBy(fn (ShiftAssignment $a) => $a->date->toDateString())
                    ->map(fn ($day) => $day->map(fn (ShiftAssignment $a) => $a->toCard())->values())
            ),
            'leaves' => $leaves->map(fn (LeaveRequest $l) => $l->toCard())->values()->all(),
            'coverage' => $this->planner->coverage($currentTeam, $from, $to),
            'rules' => [
                'maxConsecutiveHours' => $settings->rule('max_consecutive_hours'),
                'minRestHours' => $settings->rule('min_rest_hours'),
                'minRestAfterLongHours' => $settings->rule('min_rest_after_long_hours'),
                'maxWeeklyHours' => $settings->rule('max_weekly_hours'),
                'enforcement' => $settings->rule('enforcement'),
            ],
        ]);
    }

    public function store(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->ensureSupervisor($request, $currentTeam);
        $this->planner->ensureSetup($currentTeam);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('team_members', 'user_id')->where('team_id', $currentTeam->id)],
            'shift_template_id' => ['required', 'integer', Rule::exists('shift_templates', 'id')->where('team_id', $currentTeam->id)->where('active', true)],
            'dates' => ['required', 'array', 'min:1', 'max:31'],
            'dates.*' => ['date_format:Y-m-d'],
        ]);

        $agent = User::query()->findOrFail($data['user_id']);
        $template = ShiftTemplate::query()->findOrFail($data['shift_template_id']);
        $done = 0;
        $errors = [];

        foreach (array_unique($data['dates']) as $date) {
            try {
                $this->planner->assign($currentTeam, $agent, $template, $date, $request->user());
                $done++;
            } catch (ValidationException $exception) {
                $errors[] = CarbonImmutable::parse($date)->format('d/m').' : '.collect($exception->errors())->flatten()->first();
            }
        }

        if ($done === 0 && $errors !== []) {
            throw ValidationException::withMessages(['shift' => $errors]);
        }

        if ($errors !== []) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __(':done shift(s) assigned, :failed refused: :list', [
                'done' => $done, 'failed' => count($errors), 'list' => implode(' ; ', $errors),
            ])]);
        }

        return back();
    }

    public function destroy(Request $request, Team $currentTeam, ShiftAssignment $assignment): RedirectResponse
    {
        $this->ensureSupervisor($request, $currentTeam);
        abort_unless($assignment->team_id === $currentTeam->id, 404);

        $this->planner->unassign($assignment, $request->user());

        return back();
    }

    public function copyWeek(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->ensureSupervisor($request, $currentTeam);
        $this->planner->ensureSetup($currentTeam);

        $data = $request->validate(['week' => ['required', 'date_format:Y-m-d']]);
        $to = Workforce::weekStart($data['week']);
        $result = $this->planner->copyWeek($currentTeam, $to->subWeek(), $to, $request->user());

        Inertia::flash('toast', [
            'type' => $result['skipped'] === [] ? 'success' : 'warning',
            'message' => $result['skipped'] === []
                ? __(':count shift(s) copied from the previous week.', ['count' => $result['copied']])
                : __(':count shift(s) copied; not copied: :list', ['count' => $result['copied'], 'list' => implode(' ; ', array_slice($result['skipped'], 0, 5))]),
        ]);

        return back();
    }

    public function notifyWeek(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->ensureSupervisor($request, $currentTeam);
        $data = $request->validate(['week' => ['required', 'date_format:Y-m-d']]);

        $count = $this->planner->notifyWeek($currentTeam, Workforce::weekStart($data['week']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':count agent(s) notified of their schedule.', ['count' => $count])]);

        return back();
    }
}
