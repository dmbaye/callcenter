<?php

namespace App\Services;

use App\Data\Plan;
use App\Enums\TeamRole;
use App\Models\Campaign;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Plan limits and features, in one place. Seats are counted by role:
 * "agents" are members, "supervisors" are supervisors; owners and admins
 * manage the team and are free. Pending invitations hold a seat so a team
 * cannot invite past its limit and have everyone accept.
 */
class PlanLimits
{
    /**
     * @return array{agents: int, supervisors: int, queues: int}
     */
    public function usage(Team $team): array
    {
        $members = $team->memberships()
            ->whereIn('role', [TeamRole::Member->value, TeamRole::Supervisor->value, TeamRole::Planner->value])
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $invitations = $team->invitations()
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->whereIn('role', [TeamRole::Member->value, TeamRole::Supervisor->value, TeamRole::Planner->value])
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return [
            'agents' => (int) ($members[TeamRole::Member->value] ?? 0) + (int) ($invitations[TeamRole::Member->value] ?? 0),
            // Planners take a supervisor seat.
            'supervisors' => (int) ($members[TeamRole::Supervisor->value] ?? 0) + (int) ($invitations[TeamRole::Supervisor->value] ?? 0)
                + (int) ($members[TeamRole::Planner->value] ?? 0) + (int) ($invitations[TeamRole::Planner->value] ?? 0),
            'queues' => $this->queueCount($team),
        ];
    }

    /**
     * @return array<string, int|null> limits of the team's current plan (null = unlimited)
     */
    public function limits(Team $team): array
    {
        $plan = $team->plan();
        $extra = (int) $team->subscription?->extra_agents;

        return [
            'agents' => $plan->limit('agents', $extra),
            'supervisors' => $plan->limit('supervisors'),
            'queues' => $plan->limit('queues'),
            'history_months' => $plan->limit('history_months'),
            'meeting_participants' => $plan->limit('meeting_participants'),
            'meeting_minutes' => $plan->limit('meeting_minutes'),
        ];
    }

    /**
     * Writes are refused once the subscription is expired (read-only).
     */
    public function ensureUsable(Team $team, string $field = 'plan'): void
    {
        if (! $team->hasUsableSubscription()) {
            throw ValidationException::withMessages([
                $field => __('Your subscription has expired. Renew it to continue.'),
            ]);
        }
    }

    /**
     * A seat for one more member with this role (no-op for owner/admin).
     */
    public function ensureCanSeat(Team $team, TeamRole $role, string $field = 'role'): void
    {
        $this->ensureUsable($team, $field);

        $key = $this->seatKey($role);

        if ($key === null) {
            return;
        }

        $limit = $this->limits($team)[$key];

        if ($limit !== null && $this->usage($team)[$key] >= $limit) {
            throw ValidationException::withMessages([
                $field => $key === 'agents'
                    ? __('Your :plan plan includes :limit agents. Upgrade to add more.', ['plan' => $team->plan()->name, 'limit' => $limit])
                    : __('Your :plan plan includes :limit supervisors. Upgrade to add more.', ['plan' => $team->plan()->name, 'limit' => $limit]),
            ]);
        }
    }

    /**
     * Changing a member's role frees one seat and takes another.
     */
    public function ensureCanChangeRole(Team $team, TeamRole $from, TeamRole $to, string $field = 'role'): void
    {
        if ($this->seatKey($from) === $this->seatKey($to)) {
            return;
        }

        $this->ensureCanSeat($team, $to, $field);
    }

    public function ensureCanAddQueue(Team $team, string $field = 'name'): void
    {
        $this->ensureUsable($team, $field);

        $limit = $this->limits($team)['queues'];

        if ($limit !== null && $this->queueCount($team) >= $limit) {
            throw ValidationException::withMessages([
                $field => __('Your :plan plan includes :limit queues. Upgrade to add more.', ['plan' => $team->plan()->name, 'limit' => $limit]),
            ]);
        }
    }

    public function ensureFeature(Team $team, string $feature, string $field = 'plan'): void
    {
        $this->ensureUsable($team, $field);

        if (! $team->plan()->allows($feature)) {
            throw ValidationException::withMessages([
                $field => __('This feature is not included in your :plan plan.', ['plan' => $team->plan()->name]),
            ]);
        }
    }

    /**
     * Why the team cannot move to this plan right now (downgrade with too
     * many agents, supervisors or queues). Empty = allowed.
     *
     * @return list<string>
     */
    public function blockersFor(Team $team, Plan $plan, int $extraAgents = 0): array
    {
        $usage = $this->usage($team);
        $blockers = [];

        foreach (['agents' => 'agents', 'supervisors' => 'supervisors', 'queues' => 'queues'] as $key => $label) {
            $limit = $plan->limit($key, $extraAgents);

            if ($limit !== null && $usage[$key] > $limit) {
                $blockers[] = __('The :plan plan allows :limit :what; you have :count.', [
                    'plan' => $plan->name,
                    'limit' => $limit,
                    'what' => __($label),
                    'count' => $usage[$key],
                ]);
            }
        }

        return $blockers;
    }

    /**
     * Inbound queues; the queue each campaign owns is part of the campaign, not counted.
     */
    public function queueCount(Team $team): int
    {
        return $team->callQueues()
            ->whereNotIn('id', Campaign::query()
                ->where('team_id', $team->id)
                ->where('kind', Campaign::KIND_OUTBOUND)
                ->whereNotNull('call_queue_id')
                ->select('call_queue_id'))
            ->count();
    }

    /**
     * The oldest calls a team may look at (call history retention).
     */
    public function historyStart(Team $team): ?CarbonInterface
    {
        $months = $this->limits($team)['history_months'];

        return $months === null ? null : now()->subMonthsNoOverflow($months)->startOfDay();
    }

    private function seatKey(TeamRole $role): ?string
    {
        return match ($role) {
            TeamRole::Member => 'agents',
            TeamRole::Supervisor, TeamRole::Planner => 'supervisors',
            default => null,
        };
    }
}
