<?php

namespace App\Notifications\Meetings;

/** Reminder a few minutes before a planned meeting (meetings:remind). */
class MeetingStartingSoon extends MeetingNotification
{
    protected function kind(): string
    {
        return 'meeting.reminder';
    }

    protected function heading(object $notifiable): string
    {
        return __('Starting in :minutes min: :title', [
            'minutes' => max(1, (int) round(now()->diffInMinutes($this->meeting->scheduled_at, true))),
            'title' => $this->meeting->title,
        ]);
    }

    protected function body(object $notifiable): string
    {
        return $this->when();
    }
}
