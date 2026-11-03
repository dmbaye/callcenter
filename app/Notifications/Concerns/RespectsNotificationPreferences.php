<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Support\NotificationPreferences;

/**
 * Drops the channels a user turned off for the notification's category
 * (Settings → Notifications). Mail to someone who is not a user yet
 * (invitations) is never filtered.
 */
trait RespectsNotificationPreferences
{
    /** Category key of NotificationPreferences::categories(). */
    abstract protected function preferenceCategory(): string;

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    protected function allowedChannels(object $notifiable, array $channels): array
    {
        if (! $notifiable instanceof User) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            function (string $channel) use ($notifiable): bool {
                $preference = NotificationPreferences::channelFor($channel);

                return $preference === null || $notifiable->wantsNotification($this->preferenceCategory(), $preference);
            },
        ));
    }
}
