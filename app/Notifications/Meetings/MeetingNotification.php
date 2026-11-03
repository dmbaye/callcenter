<?php

namespace App\Notifications\Meetings;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Base of every meeting notification: the same payload is stored for the
 * bell (database) and pushed live (broadcast), so the header can show a
 * toast with a "Rejoindre" button and keep it in the list afterwards.
 *
 * Database and broadcast always run right away (sync); only e-mails go
 * through the queue, so a slow SMTP server never delays the live toast.
 */
abstract class MeetingNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences, SerializesModels;

    public function __construct(public Meeting $meeting, public ?User $actor = null) {}

    /** Machine name, e.g. meeting.invitation (picked by the front end for icon and action). */
    abstract protected function kind(): string;

    abstract protected function heading(object $notifiable): string;

    abstract protected function body(object $notifiable): string;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->allowedChannels($notifiable, ['database', 'broadcast']);
    }

    protected function preferenceCategory(): string
    {
        return 'meetings';
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync', 'broadcast' => 'sync'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $this->meeting->loadMissing('team');

        return [
            'kind' => $this->kind(),
            'title' => $this->heading($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->meeting->joinUrl(),
            'action' => $this->meeting->isEnded() ? null : __('Join'),
            'meeting' => [
                'uuid' => $this->meeting->uuid,
                'title' => $this->meeting->title,
                'scheduledAt' => $this->meeting->scheduled_at?->toIso8601String(),
                'team' => $this->meeting->team->name,
                'teamSlug' => $this->meeting->team->slug,
            ],
            'actor' => $this->actor?->name,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }

    /** Whether this copy goes to the person who made the change (organizer). */
    protected function isActor(object $notifiable): bool
    {
        return $this->actor !== null && $notifiable instanceof User && $notifiable->is($this->actor);
    }

    /** "jeudi 5 novembre 2026 à 09:30" in the meetings time zone. */
    protected function when(?CarbonInterface $time = null): string
    {
        $time ??= $this->meeting->scheduled_at;

        if ($time === null) {
            return '';
        }

        $local = $time->copy()->setTimezone((string) config('meetings.timezone'))->locale(app()->getLocale());

        return __(':date at :time', ['date' => $local->translatedFormat('l j F Y'), 'time' => $local->format('H:i')]);
    }

    protected function duration(): string
    {
        $minutes = $this->meeting->duration_minutes ?? 30;

        return $minutes >= 60
            ? trim(sprintf('%d h %s', intdiv($minutes, 60), $minutes % 60 ? sprintf('%02d', $minutes % 60) : ''))
            : $minutes.' min';
    }
}
