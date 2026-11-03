<?php

namespace App\Notifications\Meetings;

/** "X vous invite à une réunion maintenant": toast with "Rejoindre", kept in the bell. */
class InstantMeetingInvitation extends MeetingNotification
{
    protected function kind(): string
    {
        return 'meeting.invitation';
    }

    protected function heading(object $notifiable): string
    {
        return __(':name invites you to a meeting', ['name' => $this->actor?->name ?? __('A colleague')]);
    }

    protected function body(object $notifiable): string
    {
        return $this->meeting->title.' — '.__('starting now');
    }
}
