<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\ShiftTemplate;
use App\Models\Team;
use App\Services\Workforce\Planner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shift blocks and minimum staffing (supervisors), labor rules and leave
 * types (administrators).
 */
class SettingsController extends Controller
{
    use WorkforceAccess;

    public function __construct(private Planner $planner) {}

    public function edit(Request $request, Team $currentTeam): Response
    {
        $this->ensureSupervisor($request, $currentTeam);
        $shell = $this->shell($request, $currentTeam, $this->planner);
        $settings = $currentTeam->workforceSetting;

        return Inertia::render('workforce/Settings', [
            ...$shell,
            'templates' => $currentTeam->shiftTemplates()->get()->map(fn (ShiftTemplate $t) => $t->toCard())->all(),
            'colors' => ShiftTemplate::COLORS,
            'rules' => [
                'max_consecutive_hours' => (int) $settings->rule('max_consecutive_hours'),
                'min_rest_hours' => (int) $settings->rule('min_rest_hours'),
                'min_rest_after_long_hours' => (int) $settings->rule('min_rest_after_long_hours'),
                'max_weekly_hours' => (int) $settings->rule('max_weekly_hours'),
                'enforcement' => (string) $settings->rule('enforcement'),
                'late_tolerance_minutes' => (int) $settings->rule('late_tolerance_minutes'),
                'auto_approve_swaps' => $settings->auto_approve_swaps,
            ],
            'leaveTypes' => Planner::leaveTypeCards($currentTeam),
        ]);
    }

    /** All shift blocks at once (create, edit, deactivate). */
    public function updateShifts(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->ensureSupervisor($request, $currentTeam);

        $data = $request->validate([
            'templates' => ['required', 'array', 'min:1', 'max:12'],
            'templates.*.id' => ['nullable', 'integer'],
            'templates.*.code' => ['required', 'string', 'max:10', 'distinct'],
            'templates.*.name' => ['required', 'string', 'max:60'],
            'templates.*.start_time' => ['required', 'date_format:H:i'],
            'templates.*.end_time' => ['required', 'date_format:H:i'],
            'templates.*.break_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'templates.*.min_agents' => ['required', 'integer', 'min:0', 'max:500'],
            'templates.*.color' => ['required', Rule::in(ShiftTemplate::COLORS)],
            'templates.*.active' => ['boolean'],
        ]);

        DB::transaction(function () use ($currentTeam, $data): void {
            foreach (array_values($data['templates']) as $position => $row) {
                if ($row['start_time'] === $row['end_time']) {
                    throw ValidationException::withMessages(["templates.{$position}.end_time" => __('A shift needs different start and end times.')]);
                }

                $template = ! empty($row['id'])
                    ? $currentTeam->shiftTemplates()->findOrFail($row['id'])
                    : $currentTeam->shiftTemplates()->make();

                // Existing assignments keep their own times: only new ones use the change.
                $template->fill([...$row, 'position' => $position, 'active' => (bool) ($row['active'] ?? true)])->save();
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shifts saved.')]);

        return back();
    }

    public function updateRules(Request $request, Team $currentTeam): RedirectResponse
    {
        abort_unless($this->isAdmin($request->user(), $currentTeam), 403);

        $data = $request->validate([
            'max_consecutive_hours' => ['required', 'integer', 'min:1', 'max:24'],
            'min_rest_hours' => ['required', 'integer', 'min:0', 'max:48'],
            'min_rest_after_long_hours' => ['required', 'integer', 'min:0', 'max:72'],
            'max_weekly_hours' => ['required', 'integer', 'min:0', 'max:100'],
            'enforcement' => ['required', Rule::in(['block', 'warn'])],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'auto_approve_swaps' => ['boolean'],
        ]);

        $this->planner->ensureSetup($currentTeam)->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rules saved.')]);

        return back();
    }

    public function updateLeaveTypes(Request $request, Team $currentTeam): RedirectResponse
    {
        abort_unless($this->isAdmin($request->user(), $currentTeam), 403);

        $data = $request->validate([
            'types' => ['required', 'array', 'min:1', 'max:20'],
            'types.*.id' => ['nullable', 'integer'],
            'types.*.code' => ['required', 'string', 'max:20', 'alpha_dash', 'distinct'],
            'types.*.name' => ['required', 'string', 'max:60'],
            'types.*.paid' => ['boolean'],
            'types.*.annual_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'types.*.active' => ['boolean'],
        ]);

        DB::transaction(function () use ($currentTeam, $data): void {
            foreach (array_values($data['types']) as $position => $row) {
                $type = ! empty($row['id'])
                    ? $currentTeam->leaveTypes()->findOrFail($row['id'])
                    : $currentTeam->leaveTypes()->make();

                $type->fill([
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'paid' => (bool) ($row['paid'] ?? false),
                    'annual_days' => $row['annual_days'] ?? null,
                    'active' => (bool) ($row['active'] ?? true),
                    'position' => $position,
                ])->save();
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Leave types saved.')]);

        return back();
    }
}
