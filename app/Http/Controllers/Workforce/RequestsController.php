<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\ShiftSwap;
use App\Models\Team;
use App\Models\TimeAdjustment;
use App\Services\Workforce\LaborRules;
use App\Services\Workforce\Leaves;
use App\Services\Workforce\Planner;
use App\Services\Workforce\ShiftSwaps;
use App\Services\Workforce\Timesheets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supervisor inbox: swaps both agents agreed on, leave requests (with the
 * shifts they remove and the coverage left) and timesheet corrections.
 */
class RequestsController extends Controller
{
    use WorkforceAccess;

    public function __construct(
        private Planner $planner,
        private ShiftSwaps $swaps,
        private Leaves $leaves,
        private Timesheets $timesheets,
        private LaborRules $rules,
    ) {}

    public function index(Request $request, Team $currentTeam): Response
    {
        $this->ensureSupervisor($request, $currentTeam);
        $shell = $this->shell($request, $currentTeam, $this->planner);
        $this->swaps->expireStarted();

        // Only the agents this supervisor is in charge of.
        $managed = $this->planner->managedIds($currentTeam, $request->user());
        $mine = fn ($q) => $q->whereIn('requester_id', $managed)->orWhereIn('counterparty_id', $managed);

        $swapRelations = ['requester:id,name', 'counterparty:id,name', 'reviewer:id,name', 'requesterAssignment.template', 'counterpartyAssignment.template'];

        $pendingSwaps = $currentTeam->shiftSwaps()->with($swapRelations)
            ->where('status', ShiftSwap::PENDING_SUPERVISOR)->where($mine)->oldest()->get();

        $pendingLeave = $currentTeam->leaveRequests()->with(['user:id,name', 'type'])
            ->where('status', LeaveRequest::PENDING)->whereIn('user_id', $managed)->orderBy('start_date')->get();

        $pendingAdjustments = TimeAdjustment::query()->with(['user:id,name', 'entry.breaks'])
            ->where('team_id', $currentTeam->id)->where('status', TimeAdjustment::PENDING)->whereIn('user_id', $managed)->oldest()->get();

        return Inertia::render('workforce/Requests', [
            ...$shell,
            'swaps' => $pendingSwaps->map(fn (ShiftSwap $swap) => [
                ...$swap->toCard(),
                // Rules bent by the swap (always shown, even when the team only warns).
                'ruleWarnings' => $this->swapWarnings($currentTeam, $swap),
            ])->all(),
            'leave' => $pendingLeave->map(fn (LeaveRequest $leave) => [
                ...$leave->toCard(),
                'impact' => $this->leaves->impact($currentTeam, $leave),
                'balance' => collect($this->leaves->balances($currentTeam, $leave->user, $leave->start_date->year))->firstWhere('type.id', $leave->leave_type_id),
            ])->all(),
            'adjustments' => $pendingAdjustments->map(fn (TimeAdjustment $adjustment) => [
                ...$adjustment->toCard(),
                'current' => $adjustment->entry ? [
                    'clockIn' => $adjustment->entry->clock_in_at->format('Y-m-d H:i'),
                    'clockOut' => $adjustment->entry->clock_out_at?->format('Y-m-d H:i'),
                    'breakMinutes' => $adjustment->entry->breakMinutes(),
                ] : null,
            ])->all(),
            'history' => [
                'swaps' => $currentTeam->shiftSwaps()->with($swapRelations)
                    ->whereIn('status', [ShiftSwap::APPROVED, ShiftSwap::REJECTED])->where($mine)->latest('reviewed_at')->limit(10)->get()
                    ->map(fn (ShiftSwap $s) => $s->toCard())->all(),
                'leave' => $currentTeam->leaveRequests()->with(['user:id,name', 'type', 'reviewer:id,name'])
                    ->whereIn('status', [LeaveRequest::APPROVED, LeaveRequest::REJECTED])->whereIn('user_id', $managed)->latest('reviewed_at')->limit(10)->get()
                    ->map(fn (LeaveRequest $l) => $l->toCard())->all(),
                'adjustments' => TimeAdjustment::query()->with(['user:id,name', 'reviewer:id,name'])
                    ->where('team_id', $currentTeam->id)->whereIn('user_id', $managed)->whereIn('status', [TimeAdjustment::APPROVED, TimeAdjustment::REJECTED])
                    ->latest('reviewed_at')->limit(10)->get()->map(fn (TimeAdjustment $a) => $a->toCard())->all(),
            ],
        ]);
    }

    public function reviewSwap(Request $request, Team $currentTeam, ShiftSwap $swap): RedirectResponse
    {
        $data = $this->decision($request);
        $this->planner->ensureSetup($currentTeam);
        $this->swaps->review($currentTeam, $swap, $request->user(), $data['approve'], $data['review_note'] ?? null);

        return back();
    }

    public function reviewLeave(Request $request, Team $currentTeam, LeaveRequest $leave): RedirectResponse
    {
        $data = $this->decision($request);
        $this->leaves->review($currentTeam, $leave, $request->user(), $data['approve'], $data['review_note'] ?? null);

        return back();
    }

    public function reviewAdjustment(Request $request, Team $currentTeam, TimeAdjustment $adjustment): RedirectResponse
    {
        $data = $this->decision($request);
        $this->timesheets->reviewAdjustment($currentTeam, $adjustment, $request->user(), $data['approve'], $data['review_note'] ?? null);

        return back();
    }

    /** @return array{approve: bool, review_note?: string|null} */
    private function decision(Request $request): array
    {
        return $request->validate([
            'approve' => ['required', 'boolean'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** @return list<string> */
    private function swapWarnings(Team $team, ShiftSwap $swap): array
    {
        $give = $swap->requesterAssignment;
        $take = $swap->counterpartyAssignment;

        if ($give === null) {
            return [];
        }

        $first = $this->rules->check($team, $swap->counterparty, [[$give->starts_at, $give->ends_at]], array_filter([$take?->id]));
        $second = $take ? $this->rules->check($team, $swap->requester, [[$take->starts_at, $take->ends_at]], [$give->id]) : ['blocking' => [], 'warnings' => []];

        return array_values(array_unique([...$first['blocking'], ...$first['warnings'], ...$second['blocking'], ...$second['warnings']]));
    }
}
