<?php

namespace App\Notifications\Meetings;

/** A planned meeting has started: invitees who are not in yet get the link. */
class MeetingStarted extends MeetingNotification
{
    protected function kind(): string
    {
        return 'meeting.started';
    }

    protected function heading(object $notifiable): string
    {
        return __('The meeting has started: :title', ['title' => $this->meeting->title]);
    }

    protected function body(object $notifiable): string
    {
        return $this->actor ? __(':name is waiting for you', ['name' => $this->actor->name]) : __('Join now');
    }
}
