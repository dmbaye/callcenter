<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A new team opens on its welcome wizard: the owner and admins are sent
 * there from any team page until they finish or skip it. Members,
 * supervisors, JSON and non-GET requests pass through untouched, so an
 * agent invited early can work and nothing in flight is interrupted.
 */
class EnsureTeamOnboarded
{
    /** Route names reachable before the wizard is done. */
    private const ALWAYS_ALLOWED = [
        'onboarding.show', 'onboarding.update', 'onboarding.complete',
        'presence.join', 'presence.leave', 'pauses.status', 'monitoring.status',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('current_team');
        $user = $request->user();

        if (! $team instanceof Team
            || ! $team->needsOnboarding()
            || ! $request->isMethod('GET')
            || ($request->expectsJson() && ! $request->header('X-Inertia'))
            || in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)
            || ! $user?->teamRole($team)?->isAtLeast(TeamRole::Admin)) {
            return $next($request);
        }

        return redirect()->route('onboarding.show', ['current_team' => $team->slug]);
    }
}
