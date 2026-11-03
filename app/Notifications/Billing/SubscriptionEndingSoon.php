<?php

namespace App\Notifications\Billing;

use App\Models\Subscription;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionEndingSoon extends Notification implements ShouldQueue
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
            'kind' => 'billing.ending',
            'title' => __('Your subscription ends soon'),
            'body' => __('The subscription of :team ends on :date.', ['team' => $this->subscription->team->name, 'date' => $this->subscription->ends_at->translatedFormat('j F Y')]),
            'url' => route('billing.index'),
            'action' => __('Renew'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subscription = $this->subscription;
        $team = $subscription->team;

        $message = (new MailMessage)
            ->markdown('notifications::email', [
                'badge' => [
                    'label' => $subscription->on_trial ? __('Trial ending soon') : __('Renewal due'),
                    'tone' => 'notice',
                ],
                'summary' => [
                    __('Organization') => $team->name,
                    __('Plan') => $subscription->on_trial ? __('Free trial') : $subscription->planDetails()->name,
                    __('Ends on') => $subscription->ends_at->translatedFormat('j F Y'),
                ],
            ])
            ->greeting(__('Your subscription ends soon'))
            ->subject(__('Your subscription ends on :date — :team', [
                'date' => $subscription->ends_at->translatedFormat('j F Y'),
                'team' => $team->name,
            ]));

        if ($subscription->on_trial) {
            $message->line(__('The free trial of :team ends on :date. Choose a plan to keep your call center running.', [
                'team' => $team->name,
                'date' => $subscription->ends_at->translatedFormat('j F Y'),
            ]));
        } else {
            $message->line(__('The :plan plan of :team is paid until :date.', [
                'plan' => $subscription->planDetails()->name,
                'team' => $team->name,
                'date' => $subscription->ends_at->translatedFormat('j F Y'),
            ]))->line(__('Renew now with mobile money or card. After a :days-day grace period the account becomes read-only.', [
                'days' => (int) config('billing.grace_days', 5),
            ]));
        }

        return $message->action(__('Renew'), route('billing.index'));
    }
}
