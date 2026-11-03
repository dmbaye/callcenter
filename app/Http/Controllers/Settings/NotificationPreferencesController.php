<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Support\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the user receives in the app (bell + live toast) and by e-mail,
 * per category.
 */
class NotificationPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $isAdminSomewhere = $user->teamMemberships()
            ->whereIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->exists();

        return Inertia::render('settings/Notifications', [
            'categories' => collect(NotificationPreferences::categories())
                ->reject(fn (array $category) => $category['admin_only'] && ! $isAdminSomewhere)
                ->map(fn (array $category, string $key) => [
                    'key' => $key,
                    'label' => $category['label'],
                    'description' => $category['description'],
                ])
                ->values()
                ->all(),
            'preferences' => NotificationPreferences::resolve($user->notification_preferences),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $keys = array_keys(NotificationPreferences::categories());
        $rules = [];

        foreach ($keys as $key) {
            $rules["preferences.{$key}.".NotificationPreferences::IN_APP] = ['sometimes', 'boolean'];
            $rules["preferences.{$key}.".NotificationPreferences::EMAIL] = ['sometimes', 'boolean'];
        }

        $data = $request->validate(['preferences' => ['required', 'array'], ...$rules]);
        $user = $request->user();

        // Only known categories and channels are kept.
        $merged = NotificationPreferences::resolve($user->notification_preferences);

        foreach ($merged as $key => $channels) {
            foreach (array_keys($channels) as $channel) {
                if (isset($data['preferences'][$key][$channel])) {
                    $merged[$key][$channel] = (bool) $data['preferences'][$key][$channel];
                }
            }
        }

        $user->forceFill(['notification_preferences' => $merged])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification preferences saved.')]);

        return back();
    }
}
