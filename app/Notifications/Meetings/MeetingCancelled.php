<?php

namespace App\Notifications\Meetings;

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingCalendar;

/** Cancelled (or the attendee was removed): the attachment removes the calendar event. */
class MeetingCancelled extends EmailedMeetingNotification
{
    /** @param  list<User>|null  $removed  set when only some attendees are taken off */
    public function __construct(Meeting $meeting, ?User $actor = null, public ?array $removed = null)
    {
        parent::__construct($meeting, $actor);
    }

    protected function calendarMethod(): string
    {
        return MeetingCalendar::METHOD_CANCEL;
    }

    protected function attendees(): ?iterable
    {
        return $this->removed;
    }

    protected function kind(): string
    {
        return 'meeting.cancelled';
    }

    protected function heading(object $notifiable): string
    {
        return $this->removed !== null
            ? __('You are no longer invited: :title', ['title' => $this->meeting->title])
            : __('Meeting cancelled: :title', ['title' => $this->meeting->title]);
    }

    protected function body(object $notifiable): string
    {
        return $this->when();
    }

    protected function subject(object $notifiable): string
    {
        return __('Cancelled: :title — :when', ['title' => $this->meeting->title, 'when' => $this->when()]);
    }

    protected function badge(): array
    {
        return ['label' => __('Cancelled'), 'tone' => 'negative'];
    }

    protected function intro(object $notifiable): array
    {
        return [__('Open the attached file to remove the meeting from your calendar.')];
    }
}
