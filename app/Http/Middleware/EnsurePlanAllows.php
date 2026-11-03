<?php

namespace App\Http\Middleware;

use App\Data\Plan;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screens that belong to a plan feature (config/billing.php `features`),
 * e.g. `EnsurePlanAllows:monitoring` for live supervision and the agent log.
 */
class EnsurePlanAllows
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $team = $request->route('current_team');

        if (! $team instanceof Team) {
            $team = $request->user()?->currentTeam;
        }

        if ($team === null || $team->plan()->allows($feature)) {
            return $next($request);
        }

        $message = __('This feature is not included in the :plan plan. It is included in :plans.', [
            'plan' => $team->plan()->name,
            'plans' => Plan::namesWith($feature),
        ]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => $message]);

        return redirect()->route('dashboard', ['current_team' => $team->slug]);
    }
}
