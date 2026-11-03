<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Call-center screens (queues, IVR, campaigns, supervision, agent log,
 * pause reasons) are closed to organizations that use the app for
 * internal calls and meetings only.
 */
class EnsureCallCenterMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('current_team');

        if (! $team instanceof Team) {
            $team = $request->user()?->currentTeam;
        }

        if ($team === null || $team->isCallCenter()) {
            return $next($request);
        }

        $message = __('This feature is part of the call center. Your organization uses internal calls and meetings only: an owner can change this in Settings → Organization.');

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => $message]);

        return redirect()->route('dashboard', ['current_team' => $team->slug]);
    }
}
