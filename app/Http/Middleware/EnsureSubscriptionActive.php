<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * An expired team is read-only: pages still open (history, stats, billing)
 * but nothing that places calls, joins queues or starts a meeting runs.
 * Leaving the queues is always allowed.
 */
class EnsureSubscriptionActive
{
    /** Route names that stay available on an expired team. */
    private const ALWAYS_ALLOWED = ['presence.leave', 'pauses.status', 'monitoring.status'];

    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('current_team');

        if (! $team instanceof Team || $request->isMethodSafe()
            || in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)
            || $team->hasUsableSubscription()) {
            return $next($request);
        }

        $message = __('Your subscription has expired. Renew it to continue.');

        if ($request->header('X-Inertia') || ! $request->expectsJson()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return redirect()->back(303);
        }

        return response()->json(['message' => $message, 'billing_url' => route('billing.index')], 402);
    }
}
