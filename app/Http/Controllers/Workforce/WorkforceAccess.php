<?php

namespace App\Http\Controllers\Workforce;

use App\Models\Team;
use App\Models\User;
use App\Services\Workforce\Planner;
use App\Support\Workforce;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Role checks and shared props of the Planning pages. */
trait WorkforceAccess
{
    protected function isSupervisor(User $user, Team $team): bool
    {
        return (bool) $user->teamRole($team)?->canPlan();
    }

    protected function isAdmin(User $user, Team $team): bool
    {
        return (bool) $user->teamRole($team)?->managesWorkforce();
    }

    protected function ensureSupervisor(Request $request, Team $team): void
    {
        abort_unless($this->isSupervisor($request->user(), $team), 403);
    }

    /** The day asked for (?date= / ?week=), or today, in the workforce timezone. */
    protected function anchorDate(Request $request, string $key = 'date'): CarbonImmutable
    {
        $value = $request->query($key);

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return CarbonImmutable::parse($value, 'UTC')->startOfDay();
        }

        return Workforce::today();
    }

    /**
     * What every Planning page needs: the tabs to show and pending counts.
     *
     * @return array<string, mixed>
     */
    protected function shell(Request $request, Team $team, Planner $planner): array
    {
        $user = $request->user();
        $supervisor = $this->isSupervisor($user, $team);
        $planner->ensureSetup($team);

        return [
            'workforce' => [
                'isSupervisor' => $supervisor,
                'isAdmin' => $this->isAdmin($user, $team),
                'timezone' => Workforce::timezone(),
                'today' => Workforce::today()->toDateString(),
                'pending' => $supervisor ? (function () use ($team, $user, $planner) {
                    $managed = $planner->managedIds($team, $user);

                    return [
                        'swaps' => $team->shiftSwaps()->where('status', \App\Models\ShiftSwap::PENDING_SUPERVISOR)
                            ->where(fn ($q) => $q->whereIn('requester_id', $managed)->orWhereIn('counterparty_id', $managed))->count(),
                        'leave' => $team->leaveRequests()->where('status', \App\Models\LeaveRequest::PENDING)->whereIn('user_id', $managed)->count(),
                        'adjustments' => \App\Models\TimeAdjustment::query()->where('team_id', $team->id)
                            ->where('status', \App\Models\TimeAdjustment::PENDING)->whereIn('user_id', $managed)->count(),
                    ];
                })() : null,
                'incomingSwaps' => $team->shiftSwaps()->where('counterparty_id', $user->id)->where('status', \App\Models\ShiftSwap::PENDING_COUNTERPARTY)->count(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function dayList(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $today = Workforce::today()->toDateString();

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'weekday' => $day->locale(app()->getLocale())->translatedFormat('D'),
                'day' => $day->day,
                'month' => $day->locale(app()->getLocale())->translatedFormat('M'),
                'today' => $day->toDateString() === $today,
                'weekend' => $day->isWeekend(),
            ];
        }

        return $days;
    }
}
