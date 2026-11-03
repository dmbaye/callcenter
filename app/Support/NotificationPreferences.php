<?php

namespace App\Support;

/**
 * What each user receives, per category and channel. Stored on
 * users.notification_preferences; anything not stored uses the defaults
 * below, so a new category is on for everyone until they turn it off.
 */
final class NotificationPreferences
{
    public const IN_APP = 'in_app';

    public const EMAIL = 'email';

    /**
     * Categories shown on the settings page, with their default channels.
     * `admin_only`: only owners and admins of a team receive them.
     *
     * @return array<string, array{label: string, description: string, defaults: array<string, bool>, admin_only: bool}>
     */
    public static function categories(): array
    {
        return [
            'meetings' => [
                'label' => __('Meetings'),
                'description' => __('Invitations, changes, cancellations, reminders and meetings that start.'),
                'defaults' => [self::IN_APP => true, self::EMAIL => true],
                'admin_only' => false,
            ],
            'workforce' => [
                'label' => __('Planning'),
                'description' => __('Shift swaps, leave, timesheet corrections, roster changes and coverage alerts.'),
                'defaults' => [self::IN_APP => true, self::EMAIL => true],
                'admin_only' => false,
            ],
            'billing' => [
                'label' => __('Subscription and payments'),
                'description' => __('Renewal reminders, expiry and payment receipts of your organizations.'),
                'defaults' => [self::IN_APP => true, self::EMAIL => true],
                'admin_only' => true,
            ],
        ];
    }

    /**
     * Stored choices merged over the defaults.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, array<string, bool>>
     */
    public static function resolve(?array $stored): array
    {
        $resolved = [];

        foreach (self::categories() as $key => $category) {
            foreach ($category['defaults'] as $channel => $default) {
                $value = $stored[$key][$channel] ?? $default;
                $resolved[$key][$channel] = (bool) $value;
            }
        }

        return $resolved;
    }

    /** Laravel channel → preference channel. */
    public static function channelFor(string $laravelChannel): ?string
    {
        return match ($laravelChannel) {
            'database', 'broadcast' => self::IN_APP,
            'mail' => self::EMAIL,
            default => null,
        };
    }
}
