<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Calendar side of planned meetings: the iCalendar invitation attached to
 * e-mails (Gmail, Outlook and Apple Mail show it as an event with "Add to
 * calendar" / "Yes, Maybe, No"), the .ics download, and "add to Google /
 * Outlook" links.
 *
 * The UID is stable per meeting and SEQUENCE grows on every change, so a
 * rescheduled or cancelled meeting replaces the event already in the
 * attendee's calendar instead of adding a second one.
 */
class MeetingCalendar
{
    public const METHOD_REQUEST = 'REQUEST';

    public const METHOD_CANCEL = 'CANCEL';

    public const METHOD_PUBLISH = 'PUBLISH';

    /**
     * The iCalendar document for one meeting.
     *
     * @param  iterable<User>|null  $attendees  defaults to the meeting's invitees
     */
    public function ics(Meeting $meeting, string $method = self::METHOD_REQUEST, ?iterable $attendees = null): string
    {
        $meeting->loadMissing(['team', 'creator']);
        $attendees ??= $meeting->invitees()->get(['users.id', 'users.name', 'users.email']);
        $start = $meeting->scheduled_at ?? $meeting->created_at;
        $end = $meeting->plannedEnd() ?? $start->copy()->addMinutes(30);
        $organizerName = $meeting->creator?->name ?? config('app.name');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.$this->text((string) config('app.name')).'//Reunions//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            'BEGIN:VEVENT',
            'UID:'.$this->uid($meeting),
            'SEQUENCE:'.$meeting->calendar_sequence,
            'DTSTAMP:'.$this->utc(now()),
            'DTSTART:'.$this->utc($start),
            'DTEND:'.$this->utc($end),
            'SUMMARY:'.$this->text($meeting->title),
            'DESCRIPTION:'.$this->text($this->description($meeting)),
            'LOCATION:'.$this->text($meeting->joinUrl()),
            'URL:'.$meeting->joinUrl(),
            // The organizer is the sending address, so mail clients accept the invitation.
            'ORGANIZER;CN='.$this->param($organizerName).':mailto:'.config('mail.from.address'),
        ];

        foreach ($attendees as $attendee) {
            $lines[] = 'ATTENDEE;CN='.$this->param($attendee->name).';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:'.$attendee->email;
        }

        if ($method === self::METHOD_CANCEL) {
            $lines[] = 'STATUS:CANCELLED';
        } else {
            $lines[] = 'STATUS:CONFIRMED';
            // Phone and desktop calendars ring 10 minutes before.
            array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->text($meeting->title), 'TRIGGER:-PT10M', 'END:VALARM');
        }

        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');

        return implode("\r\n", array_map(fn (string $line) => $this->fold($line), $lines))."\r\n";
    }

    /** "Add to Google Calendar" link (opens a pre-filled event). */
    public function googleUrl(Meeting $meeting): ?string
    {
        if (! $meeting->isScheduled()) {
            return null;
        }

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $meeting->title,
            'dates' => $this->utc($meeting->scheduled_at).'/'.$this->utc($meeting->plannedEnd()),
            'details' => $this->description($meeting),
            'location' => $meeting->joinUrl(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** "Add to Outlook" link (Outlook.com / Microsoft 365 web calendar). */
    public function outlookUrl(Meeting $meeting): ?string
    {
        if (! $meeting->isScheduled()) {
            return null;
        }

        return 'https://outlook.office.com/calendar/0/deeplink/compose?'.http_build_query([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $meeting->title,
            'startdt' => $meeting->scheduled_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'enddt' => $meeting->plannedEnd()->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'body' => $this->description($meeting),
            'location' => $meeting->joinUrl(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function filename(Meeting $meeting): string
    {
        return (Str::slug($meeting->title) ?: 'reunion').'.ics';
    }

    private function description(Meeting $meeting): string
    {
        return trim(implode("\n\n", array_filter([
            $meeting->description,
            __('Join the video meeting: :url', ['url' => $meeting->joinUrl()]),
            __('Organized by :name — :team', ['name' => $meeting->creator?->name ?? config('app.name'), 'team' => $meeting->team->name]),
        ])));
    }

    private function uid(Meeting $meeting): string
    {
        return $meeting->uuid.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'callcenter');
    }

    private function utc(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Ymd\THis\Z');
    }

    /** TEXT value escaping (RFC 5545 §3.3.11). */
    private function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
    }

    /** Parameter value (CN=…): quoted, without quotes or line breaks inside. */
    private function param(string $value): string
    {
        return '"'.str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $value).'"';
    }

    /** Lines longer than 75 octets continue on the next line after a space (RFC 5545 §3.1). */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $current = '';

        foreach (mb_str_split($line) as $character) {
            if (strlen($current.$character) > ($chunks === [] ? 75 : 74)) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= $character;
        }

        $chunks[] = $current;

        return implode("\r\n ", $chunks);
    }
}
