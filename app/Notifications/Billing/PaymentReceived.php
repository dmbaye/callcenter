<?php

namespace App\Notifications\Billing;

use App\Models\Payment;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceived extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(public Payment $payment) {}

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
            'kind' => 'billing.payment',
            'title' => __('Payment received'),
            'body' => __('Thank you: :team is paid until :date.', ['team' => $this->payment->team->name, 'date' => $this->payment->period_ends_at?->translatedFormat('j F Y') ?? '']),
            'url' => route('billing.index'),
            'action' => __('See billing'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->payment;

        $plan = $payment->planDetails()?->name ?? $payment->plan;
        $amount = number_format($payment->amount, 0, ',', ' ').' '.$payment->currency;

        return (new MailMessage)
            ->subject(__('Payment received — :team', ['team' => $payment->team->name]))
            ->markdown('notifications::email', [
                'badge' => ['label' => __('Payment received'), 'tone' => 'positive'],
                'summary' => [
                    __('Organization') => $payment->team->name,
                    __('Plan') => $plan,
                    __('Amount') => $amount,
                    __('Paid until') => $payment->period_ends_at?->translatedFormat('j F Y'),
                    __('Reference') => $payment->transaction_id,
                ],
            ])
            ->greeting(__('Thank you for your payment'))
            ->line(__('We received your payment of :amount :currency for the :plan plan.', [
                'amount' => number_format($payment->amount, 0, ',', ' '),
                'currency' => $payment->currency,
                'plan' => $plan,
            ]))
            ->line(__('Your subscription is paid until :date.', [
                'date' => $payment->period_ends_at?->translatedFormat('j F Y'),
            ]))
            ->action(__('View billing'), route('billing.index'));
    }
}
