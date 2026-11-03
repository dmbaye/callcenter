<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Enums\CampaignStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Http\Requests\Teams\UpdateOrganizationProfileRequest;
use App\Models\InboundNumber;
use App\Models\OutboundRate;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskProvisioner;
use App\Services\NumberPool;
use App\Services\OutboundUsage;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Display a listing of the user's teams.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('teams/Index', [
            'teams' => $user->toUserTeams(includeCurrent: true),
        ]);
    }

    /**
     * Store a newly created team.
     */
    public function store(SaveTeamRequest $request, CreateTeam $createTeam): RedirectResponse
    {
        $team = $createTeam->handle($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created.')]);

        // A new team opens on its welcome wizard (company details, then a tour of the app).
        return to_route('onboarding.show', ['current_team' => $team->slug]);
    }

    /**
     * Show the team edit page.
     */
    public function edit(Request $request, Team $team): Response
    {
        $user = $request->user();

        return Inertia::render('teams/Edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
                'usageMode' => $team->usage_mode ?? Team::USAGE_CALL_CENTER,
            ],
            // Organization profile: the tenant identity used throughout Asterisk, read-only.
            'organization' => [
                'uuid' => $team->uuid,
                'asteriskKey' => $team->asterisk_key,
                'context' => $team->asteriskContext(),
                'endpointPrefix' => $team->asterisk_key.'_',
                'accountcode' => $team->asterisk_key,
                'owner' => $team->owner()?->name,
                'createdAt' => $team->created_at?->toIso8601String(),
                'extensionsCount' => $team->phoneExtensions()->count(),
            ],
            'profile' => $team->profilePayload(),
            'profileOptions' => Team::profileOptions(),
            'queueOptions' => $team->callQueues()->orderBy('name')->pluck('name')->values()->all(),
            'members' => $team->members()->get()->map(function (User $member) {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                    'supervisor_id' => $membership->supervisor_id,
                ];
            }),
            'invitations' => $team->invitations()
                ->whereNull('accepted_at')
                ->get()
                ->map(fn ($invitation) => [
                    'code' => $invitation->code,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at->toISOString(),
                ]),
            'permissions' => $user->toTeamPermissions($team),
            'availableRoles' => TeamRole::assignable(),
            // Short summary for the Général tab; the full page is Settings → Abonnement.
            'subscription' => $user->teamRole($team)?->isAtLeast(TeamRole::Admin)
                ? rescue(fn () => $this->subscriptionSummary($team), null, report: true)
                : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function subscriptionSummary(Team $team): array
    {
        $subscription = $team->subscription;
        $state = $subscription?->state();
        $limits = app(PlanLimits::class);
        [$from, $to] = OutboundUsage::month(null);

        return [
            'planName' => $subscription?->planDetails()->name,
            'state' => $state?->value,
            'stateLabel' => $state?->label(),
            'onTrial' => (bool) $subscription?->on_trial,
            'isFree' => (bool) $subscription?->isFree(),
            'endsAt' => $subscription?->ends_at?->toIso8601String(),
            'agents' => $limits->usage($team)['agents'],
            'agentsLimit' => $limits->limits($team)['agents'],
            // Null when the Asterisk database cannot be reached.
            'outboundAmount' => rescue(fn () => app(OutboundUsage::class)->summary($team, $from, $to)['amount'], null, report: false),
            'outboundRate' => OutboundRate::current(),
            'currency' => config('billing.currency'),
        ];
    }

    /**
     * Settings entry point of the organization: the current team's page.
     */
    public function current(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        return $team ? to_route('teams.edit', ['team' => $team->slug]) : to_route('teams.index');
    }

    /**
     * Update the specified team.
     */
    public function update(SaveTeamRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $team = DB::transaction(function () use ($request, $team) {
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $request->validated('name')]);

            return $team;
        });

        // Only the name is taken from the request: the Asterisk identifier never changes.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Update the company profile (industry, size, contacts, registry numbers…).
     */
    public function updateProfile(UpdateOrganizationProfileRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        DB::transaction(function () use ($request, $team) {
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $team->fill($request->safe()->only(Team::PROFILE_FIELDS));

            // First time the profile is usable for segmentation; kept afterwards.
            if ($team->profile_completed_at === null && $team->hasCompleteProfile()) {
                $team->profile_completed_at = now();
            }

            $team->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company profile updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Call center, or internal calls and meetings only. Switching to
     * internal pauses running campaigns; queues, numbers and history are
     * kept and come back when the call center is turned on again.
     */
    public function updateUsage(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $mode = $request->validate([
            'usage_mode' => ['required', 'string', Rule::in(Team::USAGE_MODES)],
        ])['usage_mode'];

        $team->update(['usage_mode' => $mode]);

        if ($team->isInternalOnly()) {
            $team->campaigns()->where('status', CampaignStatus::Running)->update(['status' => CampaignStatus::Paused]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $team->isInternalOnly()
            ? __('Internal calls and meetings only: call-center screens are now hidden.')
            : __('Call center turned on.')]);

        return back();
    }

    /**
     * Switch the user's current team.
     */
    public function switch(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 403);

        $request->user()->switchTeam($team);

        return back();
    }

    /**
     * Leave the specified team.
     */
    public function leave(Request $request, Team $team, AsteriskProvisioner $provisioner): RedirectResponse
    {
        Gate::authorize('leave', $team);

        $user = $request->user();
        $wasCurrent = $user->isCurrentTeam($team);

        $provisioner->removeMember($team, $user);

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($wasCurrent) {
            $user->switchToFallbackTeam($team, preferPersonal: false);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the team ":name"', ['name' => $team->name])]);

        return to_route('teams.index');
    }

    /**
     * Delete the team: members are moved to another team and the tenant is
     * removed from Asterisk so its extensions and queues stop working.
     */
    public function destroy(DeleteTeamRequest $request, Team $team, AsteriskProvisioner $provisioner, NumberPool $numbers): RedirectResponse
    {
        $user = $request->user();
        $affected = User::query()->where('current_team_id', $team->id)->get();

        $provisioner->deprovisionTeam($team);

        // Its DIDs stop ringing; numbers lent by the platform go back to the pool.
        $team->inboundNumbers()->get()->each(fn (InboundNumber $number) => $numbers->remove($number));

        DB::transaction(function () use ($team) {
            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->phoneExtensions()->delete();
            $team->delete();
        });

        // The user deleting the team lands on their alphabetically first team; others on their personal team.
        $affected->each(fn (User $affectedUser) => $affectedUser->switchToFallbackTeam($team, preferPersonal: ! $affectedUser->is($user)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return to_route('teams.index');
    }
}
