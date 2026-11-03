<?php

namespace App\Notifications\Meetings;

use App\Models\User;
use App\Services\MeetingCalendar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Planned-meeting notifications that also go by e-mail, with the
 * iCalendar invitation attached: Gmail, Outlook and Apple Mail show it as
 * an event the attendee adds to their calendar in one click.
 */
abstract class EmailedMeetingNotification extends MeetingNotification implements ShouldQueue
{
    /** iCalendar METHOD of the attachment (REQUEST, or CANCEL). */
    protected function calendarMethod(): string
    {
        return MeetingCalendar::METHOD_REQUEST;
    }

    /** @return array{label: string, tone: string} */
    abstract protected function badge(): array;

    abstract protected function subject(object $notifiable): string;

    /** @return list<string> */
    abstract protected function intro(object $notifiable): array;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // The organizer only needs the calendar copy, not a toast about their own action.
        return $this->allowedChannels($notifiable, $this->isActor($notifiable) ? ['mail'] : ['database', 'broadcast', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $calendar = app(MeetingCalendar::class);
        $meeting = $this->meeting->loadMissing(['team', 'creator']);
        $cancelled = $this->calendarMethod() === MeetingCalendar::METHOD_CANCEL;

        $message = (new MailMessage)
            ->subject($this->subject($notifiable))
            ->markdown('notifications::email', [
                'badge' => $this->badge(),
                'summary' => array_filter([
                    __('Meeting') => $meeting->title,
                    __('When') => $this->when(),
                    __('Duration') => $this->duration(),
                    __('Organizer') => $meeting->creator?->name,
                    __('Organization') => $meeting->team->name,
                ]),
            ])
            ->greeting($this->heading($notifiable));

        foreach ($this->intro($notifiable) as $line) {
            $message->line($line);
        }

        if ($meeting->description && ! $cancelled) {
            $message->line($meeting->description);
        }

        if (! $cancelled) {
            $message
                ->action(__('Join the meeting'), $meeting->joinUrl())
                ->line(__('The invitation is attached: open it to add the meeting to your calendar, or use [Google Calendar](:google) or [Outlook](:outlook).', [
                    'google' => $calendar->googleUrl($meeting),
                    'outlook' => $calendar->outlookUrl($meeting),
                ]));
        }

        // text/calendar with a METHOD: mail clients render it as an invitation.
        return $message->attachData(
            $calendar->ics($meeting, $this->calendarMethod(), $this->attendees()),
            $cancelled ? 'annulation.ics' : 'invitation.ics',
            ['mime' => 'text/calendar; charset=UTF-8; method='.$this->calendarMethod()],
        );
    }

    /** @return iterable<User>|null null = the meeting's invitees */
    protected function attendees(): ?iterable
    {
        return null;
    }
}
