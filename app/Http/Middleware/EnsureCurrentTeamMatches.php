<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes that act on the user's *current* team (settings/asterisk/*) must
 * not write to another team because a second tab switched the current
 * team in between. The frontend sends the slug of the team the page was
 * rendered for in X-Team; a mismatch is refused and the page reloaded.
 */
class EnsureCurrentTeamMatches
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = $request->header('X-Team');
        $current = $request->user()?->currentTeam?->slug;

        if ($request->isMethodSafe() || $expected === null || $expected === '' || $expected === $current) {
            return $next($request);
        }

        $message = __('Your current team changed in another tab. The page was reloaded; check the team and try again.');

        if ($request->header('X-Inertia')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return redirect()->back(303);
        }

        return response()->json(['message' => $message], 409);
    }
}
