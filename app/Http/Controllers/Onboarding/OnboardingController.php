<?php

namespace App\Http\Controllers\Onboarding;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\SaveOnboardingRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Welcome wizard of a new team: the company (name, activity, size), its
 * contact details, then the first setup steps and a guided tour of the
 * app. Owner and admins only; it can be skipped and the profile finished
 * later on the organization page.
 */
class OnboardingController extends Controller
{
    private const STEPS = ['company', 'contact', 'setup'];

    public function show(Request $request, Team $currentTeam): Response
    {
        $this->authorizeManager($request, $currentTeam);

        // Back on the wizard with a complete profile (e.g. after a reload): straight to the last step.
        $step = in_array($request->query('step'), self::STEPS, true)
            ? $request->query('step')
            : ($currentTeam->hasCompleteProfile() ? 'setup' : 'company');

        return Inertia::render('onboarding/Show', [
            'team' => [
                'name' => $currentTeam->name,
                'slug' => $currentTeam->slug,
                'onboarded' => ! $currentTeam->needsOnboarding(),
            ],
            'step' => $step,
            'profile' => $currentTeam->profilePayload(),
            'profileOptions' => Team::profileOptions(),
            'checklist' => $this->checklist($currentTeam),
            'trial' => $currentTeam->subscription ? [
                'planName' => $currentTeam->subscription->planDetails()->name,
                'endsAt' => $currentTeam->subscription->ends_at->toIso8601String(),
                'onTrial' => (bool) $currentTeam->subscription->on_trial,
            ] : null,
        ]);
    }

    /** Save one step, then move on to the next one. */
    public function update(SaveOnboardingRequest $request, Team $currentTeam): RedirectResponse
    {
        $this->authorizeManager($request, $currentTeam);

        $step = $request->validated('step');

        $team = DB::transaction(function () use ($request, $currentTeam) {
            $team = Team::whereKey($currentTeam->id)->lockForUpdate()->firstOrFail();

            if ($request->filled('name')) {
                $team->name = $request->validated('name');
            }

            $team->fill($request->safe()->only(Team::PROFILE_FIELDS));

            if ($team->profile_completed_at === null && $team->hasCompleteProfile()) {
                $team->profile_completed_at = now();
            }

            $team->save();

            return $team;
        });

        $next = self::STEPS[array_search($step, self::STEPS, true) + 1];

        // The slug follows the name, so the next step lives at the new URL.
        return to_route('onboarding.show', ['current_team' => $team->slug, 'step' => $next]);
    }

    /** Where each first setup step is done (the wizard can end on one of them). */
    private const SETUP_ROUTES = [
        'extensions' => 'asterisk.pjsip.index',
        'queues' => 'asterisk.queues.index',
        'numbers' => 'asterisk.ivr.index',
        'members' => 'teams.edit',
    ];

    /** Finish (or skip) the wizard: the team opens normally from now on. */
    public function complete(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->authorizeManager($request, $currentTeam);

        $request->validate([
            'skipped' => ['sometimes', 'boolean'],
            'next' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::SETUP_ROUTES))],
        ]);

        if ($currentTeam->needsOnboarding()) {
            $currentTeam->forceFill(['onboarded_at' => now()])->save();
        }

        $skipped = $request->boolean('skipped');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $skipped
                ? __('You can finish your company profile at any time in Settings → Organization.')
                : __('Welcome aboard! Your team is ready.'),
        ]);

        $next = $request->input('next');

        if ($next === 'members') {
            return to_route('teams.edit', ['team' => $currentTeam->slug]);
        }

        if ($next !== null) {
            return to_route(self::SETUP_ROUTES[$next]);
        }

        return to_route('dashboard', ['current_team' => $currentTeam->slug]);
    }

    /**
     * What a new call center sets up first, with what is already done.
     *
     * @return list<array{key: string, done: bool, count: int}>
     */
    private function checklist(Team $team): array
    {
        $counts = [
            'extensions' => $team->phoneExtensions()->count(),
            'queues' => $team->callQueues()->count(),
            'numbers' => $team->inboundNumbers()->count(),
            'members' => $team->memberships()->count() + $team->invitations()->whereNull('accepted_at')->count(),
        ];

        $items = [
            ['key' => 'extensions', 'done' => $counts['extensions'] > 0, 'count' => $counts['extensions']],
            ['key' => 'queues', 'done' => $counts['queues'] > 0, 'count' => $counts['queues']],
            ['key' => 'numbers', 'done' => $counts['numbers'] > 0, 'count' => $counts['numbers']],
            // The owner is always a member: done once a second person is in or invited.
            ['key' => 'members', 'done' => $counts['members'] > 1, 'count' => $counts['members']],
        ];

        // Internal calls and meetings only: no queues, no inbound numbers.
        return $team->isInternalOnly()
            ? array_values(array_filter($items, fn (array $item) => ! in_array($item['key'], ['queues', 'numbers'], true)))
            : $items;
    }

    private function authorizeManager(Request $request, Team $team): void
    {
        abort_unless((bool) $request->user()->teamRole($team)?->isAtLeast(TeamRole::Admin), 403);
    }
}
