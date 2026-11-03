<?php

namespace App\Notifications\Meetings;

/** New date, duration or title: the attached invitation replaces the calendar event. */
class MeetingUpdated extends EmailedMeetingNotification
{
    protected function kind(): string
    {
        return 'meeting.updated';
    }

    protected function heading(object $notifiable): string
    {
        return __('Meeting changed: :title', ['title' => $this->meeting->title]);
    }

    protected function body(object $notifiable): string
    {
        return __('New time: :when', ['when' => $this->when()]);
    }

    protected function subject(object $notifiable): string
    {
        return __('Updated invitation: :title — :when', ['title' => $this->meeting->title, 'when' => $this->when()]);
    }

    protected function badge(): array
    {
        return ['label' => __('Changed'), 'tone' => 'notice'];
    }

    protected function intro(object $notifiable): array
    {
        return [__(':name changed this meeting. Your calendar is updated when you open the attached invitation.', ['name' => $this->actor?->name ?? __('The organizer')])];
    }
}
