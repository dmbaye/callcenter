<?php

namespace App\Http\Middleware;

use App\Data\Plan;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Interface language (fr | en), see SetLocale.
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            // Shared on every page: the global softphone (AppLayout) registers from this.
            // An expired (read-only) team gets none, so no phone registers.
            'sipCredentials' => fn () => $user?->currentTeam && $user->currentTeam->hasUsableSubscription()
                ? $user->phoneExtensionFor($user->currentTeam)?->sipCredentials()
                : null,
            // The softphone skips the call outcome for calls with these (colleagues).
            'teamExtensions' => fn () => $user?->currentTeam
                ? $user->currentTeam->phoneExtensions()->pluck('extension')->map(fn ($e) => (string) $e)->values()->all()
                : [],
            'dialing' => [
                'countryCode' => (string) config('asterisk.campaigns.country_code', '223'),
                'nationalLength' => (int) config('asterisk.campaigns.national_length', 8),
            ],
            'billing' => fn () => $user?->currentTeam ? $this->billing($user->currentTeam) : null,
            'unreadNotifications' => fn () => $user?->unreadNotifications()->count() ?? 0,
            'presenceHeartbeat' => (int) config('asterisk.presence.heartbeat_interval', 60),
        ];
    }

    /**
     * Plan summary for navigation, banners and feature gates.
     *
     * @return array<string, mixed>|null
     */
    private function billing(Team $team): ?array
    {
        $subscription = $team->subscription;

        if ($subscription === null) {
            return null;
        }

        $state = $subscription->state();

        return [
            'plan' => $subscription->plan,
            'planName' => $subscription->planDetails()->name,
            'state' => $state->value,
            'stateLabel' => $state->label(),
            'onTrial' => $subscription->on_trial,
            'endsAt' => $subscription->ends_at->toIso8601String(),
            'features' => $subscription->planDetails()->features,
            'isFree' => $subscription->isFree(),
            // "Croissance, Pro et Corporate": for "included in …" hints next to locked features.
            'includedIn' => collect(['monitoring', 'campaigns', 'video', 'hd_video', 'full_hd_video', 'recording', 'call_recording'])
                ->mapWithKeys(fn (string $feature) => [$feature => Plan::namesWith($feature)])
                ->all(),
        ];
    }
}
