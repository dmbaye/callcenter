<?php

namespace App\Notifications\Meetings;

/** A planned meeting: invitation by e-mail (with calendar file), in the bell and live. */
class MeetingScheduled extends EmailedMeetingNotification
{
    protected function kind(): string
    {
        return 'meeting.scheduled';
    }

    protected function heading(object $notifiable): string
    {
        return $this->isActor($notifiable)
            ? __('Your meeting is scheduled')
            : __(':name invites you to a meeting', ['name' => $this->actor?->name ?? __('A colleague')]);
    }

    protected function body(object $notifiable): string
    {
        return $this->meeting->title.' — '.$this->when();
    }

    protected function subject(object $notifiable): string
    {
        return __('Invitation: :title — :when', ['title' => $this->meeting->title, 'when' => $this->when()]);
    }

    protected function badge(): array
    {
        return ['label' => __('Invitation'), 'tone' => 'informative'];
    }

    protected function intro(object $notifiable): array
    {
        return $this->isActor($notifiable)
            ? [__('The invitations have been sent. Add the meeting to your calendar with the attached file.')]
            : [__('You are invited to a video meeting. Join from your browser at the planned time: no software to install.')];
    }
}
