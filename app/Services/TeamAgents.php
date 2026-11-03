<?php

namespace App\Services;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single definition of "an agent of a team", shared by dashboards,
 * call history and live monitoring: a member or supervisor of the team who
 * has a phone extension in it. Owners and admins manage the team; they are
 * not counted, listed or monitored as agents.
 *
 * Extensions and queues are eager loaded (team-scoped), so callers can use
 * phoneExtensionFor() / teamQueues() on each user without extra queries.
 */
class TeamAgents
{
    /** @var list<TeamRole> */
    public const ROLES = [TeamRole::Member, TeamRole::Supervisor];

    /** @return Collection<int, User> */
    public function for(Team $team): Collection
    {
        return $this->members($team)
            ->filter(fn (User $user) => $user->phoneExtensionFor($team) !== null)
            ->values();
    }

    /**
     * Every agent-role member, with or without an extension.
     *
     * @return Collection<int, User>
     */
    public function members(Team $team): Collection
    {
        return $team->members()
            ->wherePivotIn('role', array_map(fn (TeamRole $role) => $role->value, self::ROLES))
            ->with([
                'phoneExtensions' => fn ($query) => $query->where('team_id', $team->id),
                'callQueues' => fn ($query) => $query->where('call_queues.team_id', $team->id),
            ])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email']);
    }

    public function isAgent(Team $team, User $user): bool
    {
        return in_array($user->teamRole($team), self::ROLES, true)
            && $user->phoneExtensionFor($team) !== null;
    }
}
