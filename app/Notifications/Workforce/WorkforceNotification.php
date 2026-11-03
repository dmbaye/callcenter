<?php

namespace App\Notifications\Workforce;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Planning notifications (swaps, leave, timesheet adjustments, roster
 * changes, coverage alerts): bell + live toast + e-mail, filtered by the
 * user's « Planning » preferences. The bell and toast go out at once, the
 * e-mail through the queue.
 *
 * Texts are translation keys with their replacements, resolved in the
 * recipient's language when the notification is sent.
 */
class WorkforceNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    /**
     * @param  array<string, string|int|float|null>  $replace
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public string $url,
        public array $replace = [],
        public ?string $actionLabel = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->allowedChannels($notifiable, ['database', 'broadcast', 'mail']);
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync', 'broadcast' => 'sync'];
    }

    protected function preferenceCategory(): string
    {
        return 'workforce';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'workforce.'.$this->kind,
            'title' => __($this->title, $this->replace),
            'body' => __($this->body, $this->replace),
            'url' => $this->url,
            'action' => __($this->actionLabel ?? 'Open'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__($this->title, $this->replace))
            ->greeting(__($this->title, $this->replace))
            ->line(__($this->body, $this->replace))
            ->action(__($this->actionLabel ?? 'Open'), $this->url);
    }
}
