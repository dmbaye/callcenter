<?php

namespace App\Services\Workforce;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Workforce\WorkforceNotification;
use App\Support\Workforce;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Leave: balances per type and calendar year, requests (full days or part
 * of a day), supervisor review with staffing conflicts, and the roster
 * impact (covered shifts removed, coverage alerts).
 *
 * Full days count working days (Monday to Saturday, « jours ouvrables »);
 * a partial request counts its hours / hours_per_day.
 */
class Leaves
{
    public function __construct(private Planner $planner) {}

    /**
     * @return list<array{type: array<string, mixed>, allowance: float|null, used: float, pending: float, remaining: float|null}>
     */
    public function balances(Team $team, User $user, int $year): array
    {
        $requests = LeaveRequest::query()
            ->where('team_id', $team->id)->where('user_id', $user->id)
            ->whereIn('status', [LeaveRequest::APPROVED, LeaveRequest::PENDING])
            ->whereYear('start_date', $year)
            ->get()
            ->groupBy('leave_type_id');

        return $team->leaveTypes()->where('active', true)->get()->map(function (LeaveType $type) use ($requests) {
            $rows = $requests->get($type->id, collect());
            $used = (float) $rows->where('status', LeaveRequest::APPROVED)->sum('days');
            $pending = (float) $rows->where('status', LeaveRequest::PENDING)->sum('days');

            return [
                'type' => ['id' => $type->id, 'name' => $type->name, 'paid' => $type->paid],
                'allowance' => $type->annual_days,
                'used' => round($used, 2),
                'pending' => round($pending, 2),
                'remaining' => $type->hasBalance() ? round($type->annual_days - $used - $pending, 2) : null,
            ];
        })->values()->all();
    }

    /**
     * @param  array{leave_type_id: int, start_date: string, end_date?: string|null, start_time?: string|null, end_time?: string|null, reason?: string|null}  $data
     */
    public function submit(Team $team, User $user, array $data): LeaveRequest
    {
        $type = $team->leaveTypes()->where('active', true)->find($data['leave_type_id'])
            ?? throw ValidationException::withMessages(['leave_type_id' => __('Choose a type of leave.')]);

        $start = CarbonImmutable::parse($data['start_date'], 'UTC')->startOfDay();
        $partial = ! empty($data['start_time']) && ! empty($data['end_time']);
        $end = $partial ? $start : CarbonImmutable::parse($data['end_date'] ?? $data['start_date'], 'UTC')->startOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => __('The end date is before the start date.')]);
        }

        if ($start->lt(Workforce::today()->subDays(30))) {
            throw ValidationException::withMessages(['start_date' => __('Leave older than 30 days cannot be requested.')]);
        }

        $request = new LeaveRequest([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'start_time' => $partial ? $data['start_time'] : null,
            'end_time' => $partial ? $data['end_time'] : null,
            'reason' => $data['reason'] ?? null,
            'status' => LeaveRequest::PENDING,
        ]);
        $request->days = $this->countDays($request);

        if ($request->days <= 0) {
            throw ValidationException::withMessages(['start_date' => __('These dates contain no working day.')]);
        }

        $this->ensureNoOverlap($team, $user, $request);
        $this->ensureBalance($team, $user, $type, $request);

        $request->save();
        $request->setRelation('type', $type)->setRelation('user', $user);

        $this->planner->safely(fn () => Notification::send($this->planner->reviewersFor($team, $user), new WorkforceNotification(
            'leave.requested',
            'Leave request to review',
            ':name asks for :type: :period.',
            route('workforce.requests', ['current_team' => $team->slug]),
            ['name' => $user->name, 'type' => $type->name, 'period' => $this->periodText($request)],
            'Review',
        )));

        return $request;
    }

    /**
     * What approving would do: the shifts it removes and the coverage left.
     *
     * @return array{assignments: list<array<string, mixed>>, partial: list<array<string, mixed>>, shortages: list<string>}
     */
    public function impact(Team $team, LeaveRequest $request): array
    {
        [$covered, $partial] = $this->affectedAssignments($team, $request);
        $shortages = [];

        foreach ($covered as $assignment) {
            $template = $assignment->template;

            if ($template === null || $template->min_agents <= 0) {
                continue;
            }

            $remaining = ShiftAssignment::query()
                ->where('team_id', $team->id)
                ->where('shift_template_id', $template->id)
                ->whereDate('date', $assignment->date->toDateString())
                ->whereKeyNot($assignment->id)
                ->count();

            if ($remaining < $template->min_agents) {
                $shortages[] = __(':shift of :date: :count agent(s) left for :min required.', [
                    'shift' => $template->name, 'date' => $assignment->date->format('d/m'), 'count' => $remaining, 'min' => $template->min_agents,
                ]);
            }
        }

        return [
            'assignments' => $covered->map(fn (ShiftAssignment $a) => $a->toCard())->values()->all(),
            'partial' => $partial->map(fn (ShiftAssignment $a) => $a->toCard())->values()->all(),
            'shortages' => $shortages,
        ];
    }

    public function review(Team $team, LeaveRequest $request, User $supervisor, bool $approve, ?string $note): LeaveRequest
    {
        abort_unless($request->team_id === $team->id && $this->planner->canManage($team, $supervisor, $request->user_id), 403);

        if ($request->status !== LeaveRequest::PENDING) {
            throw ValidationException::withMessages(['leave' => __('This request was already reviewed.')]);
        }

        if (! $approve) {
            if (blank($note)) {
                throw ValidationException::withMessages(['review_note' => __('Give the reason of the refusal.')]);
            }

            $request->update(['status' => LeaveRequest::REJECTED, 'reviewed_by' => $supervisor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->tellAgent($team, $request, false);

            return $request;
        }

        $impact = $this->impact($team, $request);

        DB::transaction(function () use ($team, $request, $supervisor, $note): void {
            [$covered] = $this->affectedAssignments($team, $request);

            // Shifts fully inside the leave leave the roster; partly covered ones stay (the timesheet deducts the leave).
            foreach ($covered as $assignment) {
                $this->planner->unassign($assignment, $supervisor, notify: false);
            }

            $request->update(['status' => LeaveRequest::APPROVED, 'reviewed_by' => $supervisor->id, 'reviewed_at' => now(), 'review_note' => $note]);
        });

        $this->tellAgent($team, $request, true);

        if ($impact['shortages'] !== []) {
            $this->planner->safely(fn () => Notification::send($this->planner->supervisors($team), new WorkforceNotification(
                'coverage.alert',
                'Shifts under minimum staffing',
                'After the leave of :name: :list',
                route('workforce.roster', ['current_team' => $team->slug, 'date' => $request->start_date->toDateString()]),
                ['name' => $request->user->name, 'list' => implode(' ; ', $impact['shortages'])],
                'Open the roster',
            )));
        }

        return $request;
    }

    /** The agent withdraws a request (pending, or approved and not started). */
    public function cancel(Team $team, LeaveRequest $request, User $user): void
    {
        abort_unless($request->team_id === $team->id && $request->user_id === $user->id, 403);

        $cancellable = $request->status === LeaveRequest::PENDING
            || ($request->status === LeaveRequest::APPROVED && $request->start_date->gt(Workforce::today()));

        if (! $cancellable) {
            throw ValidationException::withMessages(['leave' => __('This request can no longer be cancelled.')]);
        }

        $wasApproved = $request->status === LeaveRequest::APPROVED;
        $request->update(['status' => LeaveRequest::CANCELLED]);

        if ($wasApproved) {
            $this->planner->safely(fn () => Notification::send($this->planner->reviewersFor($team, $user), new WorkforceNotification(
                'leave.cancelled',
                'Leave cancelled',
                ':name cancelled their leave of :period: the roster may need them again.',
                route('workforce.roster', ['current_team' => $team->slug, 'date' => $request->start_date->toDateString()]),
                ['name' => $user->name, 'period' => $this->periodText($request)],
                'Open the roster',
            )));
        }
    }

    public function countDays(LeaveRequest $request): float
    {
        if ($request->isPartial()) {
            [$start, $end] = $request->period();

            return round($start->diffInMinutes($end) / 60 / (float) config('workforce.hours_per_day', 8), 2);
        }

        $days = 0;

        for ($day = $request->start_date; $day->lte($request->end_date); $day = $day->addDay()) {
            $days += $day->isSunday() ? 0 : 1;
        }

        return (float) $days;
    }

    public function periodText(LeaveRequest $request): string
    {
        if ($request->isPartial()) {
            return $request->start_date->format('d/m/Y').' '.substr((string) $request->start_time, 0, 5).'–'.substr((string) $request->end_time, 0, 5);
        }

        return $request->start_date->equalTo($request->end_date)
            ? $request->start_date->format('d/m/Y')
            : $request->start_date->format('d/m/Y').' → '.$request->end_date->format('d/m/Y');
    }

    /**
     * @return array{0: Collection<int, ShiftAssignment>, 1: Collection<int, ShiftAssignment>} [fully covered, partly covered]
     */
    private function affectedAssignments(Team $team, LeaveRequest $request): Collection
    {
        [$off, $back] = $request->period();

        $overlapping = ShiftAssignment::query()->with(['template', 'user'])
            ->where('team_id', $team->id)->where('user_id', $request->user_id)
            ->where('starts_at', '<', $back)->where('ends_at', '>', $off)
            ->orderBy('starts_at')->get();

        return $overlapping->partition(fn (ShiftAssignment $a) => $a->starts_at->gte($off) && $a->ends_at->lte($back));
    }

    private function ensureNoOverlap(Team $team, User $user, LeaveRequest $request): void
    {
        [$off, $back] = $request->period();

        $overlap = LeaveRequest::query()
            ->where('team_id', $team->id)->where('user_id', $user->id)
            ->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
            ->whereDate('start_date', '<=', $request->end_date->toDateString())
            ->whereDate('end_date', '>=', $request->start_date->toDateString())
            ->get()
            ->contains(function (LeaveRequest $other) use ($off, $back): bool {
                [$a, $b] = $other->period();

                return $off->lt($b) && $back->gt($a);
            });

        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => __('You already have leave requested on these dates.')]);
        }
    }

    private function ensureBalance(Team $team, User $user, LeaveType $type, LeaveRequest $request): void
    {
        if (! $type->hasBalance()) {
            return;
        }

        // A request spanning two years is counted on the year it starts.
        $balance = collect($this->balances($team, $user, $request->start_date->year))
            ->firstWhere('type.id', $type->id);
        $remaining = (float) ($balance['remaining'] ?? 0);

        if ($request->days > $remaining) {
            throw ValidationException::withMessages(['leave_type_id' => __('Not enough :type left: :remaining day(s) available, :days requested.', [
                'type' => $type->name, 'remaining' => $remaining, 'days' => $request->days,
            ])]);
        }
    }

    private function tellAgent(Team $team, LeaveRequest $request, bool $approved): void
    {
        $request->loadMissing(['user', 'type']);

        $this->planner->safely(fn () => $request->user->notify(new WorkforceNotification(
            $approved ? 'leave.approved' : 'leave.rejected',
            $approved ? 'Leave approved' : 'Leave refused',
            $approved ? 'Your :type of :period is approved.' : 'Your :type of :period was refused: :note',
            route('workforce.leave', ['current_team' => $team->slug]),
            ['type' => $request->type?->name, 'period' => $this->periodText($request), 'note' => (string) $request->review_note],
            'See my leave',
        )));
    }
}
