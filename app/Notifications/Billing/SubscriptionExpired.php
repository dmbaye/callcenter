<?php

namespace App\Notifications\Billing;

use App\Models\Subscription;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpired extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(public Subscription $subscription) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->allowedChannels($notifiable, ['database', 'mail']);
    }

    protected function preferenceCategory(): string
    {
        return 'billing';
    }

    /** @return array<string, mixed> In-app copy for the bell. */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'billing.expired',
            'title' => __('Subscription expired'),
            'body' => __(':team is read-only until the next payment.', ['team' => $this->subscription->team->name]),
            'url' => route('billing.index'),
            'action' => __('Renew'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', [
                'badge' => ['label' => __('Subscription expired'), 'tone' => 'negative'],
            ])
            ->greeting(__('Your call center is paused'))
            ->subject(__('Subscription expired — :team', ['team' => $this->subscription->team->name]))
            ->line(__(':team is now read-only: agents can no longer take calls. Your data is kept.', [
                'team' => $this->subscription->team->name,
            ]))
            ->line(__('Pay for a new period to reactivate it immediately.'))
            ->action(__('Renew'), route('billing.index'));
    }
}
