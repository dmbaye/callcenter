<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeamMember;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamMemberRequest;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskProvisioner;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    /**
     * Create a team member directly.
     */
    public function store(
        CreateTeamMemberRequest $request,
        Team $team,
        CreateTeamMember $createTeamMember,
    ): RedirectResponse {
        Gate::authorize('addMember', $team);

        abort_if(
            $request->user()->teamRole($team) !== TeamRole::Owner && $request->validated('role') === TeamRole::Admin->value,
            403,
            __('Only the owner can add an administrator.'),
        );

        $createTeamMember->handle($team, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team member created.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Update the specified team member's role.
     */
    public function update(UpdateTeamMemberRequest $request, Team $team, User $user, PlanLimits $limits): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        $membership = $team->memberships()->where('user_id', $user->id)->firstOrFail();

        abort_if($membership->role === TeamRole::Owner, 403, __('The team owner role cannot be changed.'));
        abort_if(
            $request->user()->teamRole($team) !== TeamRole::Owner && $membership->role === TeamRole::Admin,
            403,
            __('Only the owner can change an administrator.'),
        );

        $newRole = TeamRole::from($request->validated('role'));

        abort_if(
            $request->user()->teamRole($team) !== TeamRole::Owner && $newRole === TeamRole::Admin,
            403,
            __('Only the owner can promote a member to administrator.'),
        );

        $limits->ensureCanChangeRole($team, $membership->role, $newRole);

        $membership->update(['role' => $newRole]);

        // A member who no longer supervises leaves their agents unassigned.
        if (! $newRole->isAtLeast(TeamRole::Supervisor)) {
            $team->memberships()->where('supervisor_id', $user->id)->update(['supervisor_id' => null]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove a member: queue presence, extension, PJSIP endpoint and queue
     * assignments go with the membership.
     */
    public function destroy(Request $request, Team $team, User $user, AsteriskProvisioner $provisioner): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        $role = $user->teamRole($team);

        abort_if($role === null, 404);
        abort_if($role === TeamRole::Owner, 403, __('The team owner cannot be removed.'));
        abort_if(
            $request->user()->teamRole($team) !== TeamRole::Owner && $role === TeamRole::Admin,
            403,
            __('Only the owner can remove an administrator.'),
        );

        $provisioner->removeMember($team, $user);

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        $team->memberships()->where('supervisor_id', $user->id)->update(['supervisor_id' => null]);

        if ($user->isCurrentTeam($team)) {
            $user->switchToFallbackTeam($team);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Put a member under a supervisor (or none): that supervisor reviews
     * their swaps, leave and timesheets.
     */
    public function assignSupervisor(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        $membership = $team->memberships()->where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'supervisor_id' => ['nullable', 'integer'],
        ]);

        $supervisorId = isset($data['supervisor_id']) ? (int) $data['supervisor_id'] : null;

        if ($supervisorId !== null) {
            $supervisor = $team->memberships()->where('user_id', $supervisorId)->first();

            if ($supervisor === null || ! $supervisor->role->isAtLeast(TeamRole::Supervisor) || $supervisorId === $user->id) {
                throw ValidationException::withMessages(['supervisor_id' => __('Choose a supervisor, an administrator or the owner of the team.')]);
            }
        }

        $membership->update(['supervisor_id' => $supervisorId]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supervisor updated.')]);

        return back();
    }
}
