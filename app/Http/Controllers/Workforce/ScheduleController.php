<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwap;
use App\Models\Team;
use App\Models\User;
use App\Services\Workforce\Planner;
use App\Services\Workforce\ShiftSwaps;
use App\Services\Workforce\Timesheets;
use App\Support\Workforce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The agent's own side: schedule of the week, clock in/out and breaks,
 * shift swaps asked and received.
 */
class ScheduleController extends Controller
{
    use WorkforceAccess;

    public function __construct(private Planner $planner, private ShiftSwaps $swaps, private Timesheets $timesheets) {}

    public function show(Request $request, Team $currentTeam): Response
    {
        $user = $request->user();
        $shell = $this->shell($request, $currentTeam, $this->planner);
        $this->swaps->expireStarted();

        $weekStart = Workforce::weekStart($this->anchorDate($request));
        $now = Workforce::now();

        $mine = ShiftAssignment::query()->with('template')
            ->where('team_id', $currentTeam->id)->where('user_id', $user->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->orderBy('starts_at')->get();

        // What can be offered or asked for in a swap: shifts not started, next 45 days.
        $upcoming = ShiftAssignment::query()->with(['template', 'user:id,name'])
            ->where('team_id', $currentTeam->id)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<', $now->addDays(45))
            ->orderBy('starts_at')->get();

        $swaps = $currentTeam->shiftSwaps()
            ->with(['requester:id,name', 'counterparty:id,name', 'reviewer:id,name', 'requesterAssignment.template', 'counterpartyAssignment.template'])
            ->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('counterparty_id', $user->id))
            ->latest()->limit(30)->get();

        $entry = $this->timesheets->openEntry($currentTeam, $user);

        return Inertia::render('workforce/MySchedule', [
            ...$shell,
            'weekStart' => $weekStart->toDateString(),
            'previous' => $weekStart->subWeek()->toDateString(),
            'next' => $weekStart->addWeek()->toDateString(),
            'days' => $this->dayList($weekStart, $weekStart->addDays(6)),
            'shifts' => $mine->map(fn (ShiftAssignment $a) => $a->toCard())->values()->all(),
            'myUpcoming' => $upcoming->where('user_id', $user->id)->map(fn (ShiftAssignment $a) => $a->toCard())->values()->all(),
            'colleagues' => $this->planner->agents($currentTeam)->reject(fn (User $u) => $u->is($user))
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            'colleagueShifts' => $upcoming->where('user_id', '!=', $user->id)->map(fn (ShiftAssignment $a) => $a->toCard())->values()->all(),
            'swaps' => $swaps->map(fn (ShiftSwap $s) => [...$s->toCard(), 'mine' => $s->requester_id === $user->id])->all(),
            'clock' => [
                'entryId' => $entry?->id,
                'since' => $entry?->clock_in_at->format('Y-m-d H:i'),
                'onBreak' => $entry?->openBreak() !== null,
                'breakSince' => $entry?->openBreak()?->started_at->format('Y-m-d H:i'),
                'workedMinutes' => $entry?->workedMinutes(),
            ],
        ]);
    }

    public function requestSwap(Request $request, Team $currentTeam): RedirectResponse
    {
        $data = $request->validate([
            'requester_assignment_id' => ['required', 'integer'],
            'counterparty_id' => ['required', 'integer', Rule::exists('team_members', 'user_id')->where('team_id', $currentTeam->id)],
            'counterparty_assignment_id' => ['nullable', 'integer'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $give = ShiftAssignment::query()->where('team_id', $currentTeam->id)->findOrFail($data['requester_assignment_id']);
        $take = isset($data['counterparty_assignment_id'])
            ? ShiftAssignment::query()->where('team_id', $currentTeam->id)->findOrFail($data['counterparty_assignment_id'])
            : null;

        $this->planner->ensureSetup($currentTeam);
        $this->swaps->request($currentTeam, $request->user(), $give, User::query()->findOrFail($data['counterparty_id']), $take, $data['message'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Swap proposed: your colleague has been notified.')]);

        return back();
    }

    public function respond(Request $request, Team $currentTeam, ShiftSwap $swap): RedirectResponse
    {
        $accept = $request->validate(['accept' => ['required', 'boolean']])['accept'];
        $this->planner->ensureSetup($currentTeam);
        $swap = $this->swaps->respond($currentTeam, $swap, $request->user(), (bool) $accept);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($swap->status) {
            ShiftSwap::APPROVED => __('Swap accepted and approved automatically: your schedule is updated.'),
            ShiftSwap::PENDING_SUPERVISOR => __('Swap accepted: a supervisor will confirm it.'),
            default => __('Swap declined.'),
        }]);

        return back();
    }

    public function cancel(Request $request, Team $currentTeam, ShiftSwap $swap): RedirectResponse
    {
        $this->swaps->cancel($currentTeam, $swap, $request->user());

        return back();
    }

    public function clock(Request $request, Team $currentTeam, string $action): RedirectResponse
    {
        $this->planner->ensureSetup($currentTeam);
        $user = $request->user();

        match ($action) {
            'in' => $this->timesheets->clockIn($currentTeam, $user),
            'out' => $this->timesheets->clockOut($currentTeam, $user),
            'break-start' => $this->timesheets->startBreak($currentTeam, $user),
            'break-end' => $this->timesheets->endBreak($currentTeam, $user),
        };

        return back();
    }
}
