<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionState;
use App\Models\Payment;
use App\Models\Subscription;
use App\Notifications\Billing\SubscriptionEndingSoon;
use App\Notifications\Billing\SubscriptionExpired;
use App\Services\AsteriskQueueMembers;
use App\Services\Billing;
use App\Services\Payments\PaymentGateways;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Hourly billing housekeeping:
 * - reminder mail before the end of a period (once per period),
 * - on expiry: agents leave the Asterisk queues (no more inbound calls), mail,
 * - reconciliation of pending payments whose notification never arrived.
 */
class ProcessSubscriptions extends Command
{
    protected $signature = 'billing:process';

    protected $description = 'Send renewal reminders, expire unpaid subscriptions and reconcile pending payments';

    public function handle(Billing $billing, PaymentGateways $gateways, AsteriskQueueMembers $queueMembers): int
    {
        $reminded = $this->sendReminders($billing);
        $expired = $this->expire($billing, $queueMembers);
        $reconciled = $gateways->anyConfigured() ? $this->reconcile($billing, $gateways) : 0;

        $this->info("Reminders: {$reminded}, expired: {$expired}, payments reconciled: {$reconciled}");

        return self::SUCCESS;
    }

    private function sendReminders(Billing $billing): int
    {
        $count = 0;

        Subscription::query()->with('team')
            ->whereBetween('ends_at', [now(), now()->addDays((int) config('billing.reminder_days', 7))])
            ->get()
            ->filter(fn (Subscription $subscription) => $subscription->team !== null
                && ! $subscription->isFree()
                && ! $subscription->reminded_for?->equalTo($subscription->ends_at))
            ->each(function (Subscription $subscription) use ($billing, &$count): void {
                Notification::send($billing->billingContacts($subscription->team), new SubscriptionEndingSoon($subscription));
                $subscription->update(['reminded_for' => $subscription->ends_at]);
                $count++;
            });

        return $count;
    }

    private function expire(Billing $billing, AsteriskQueueMembers $queueMembers): int
    {
        $count = 0;

        Subscription::query()->with('team')
            ->where('ends_at', '<=', now())
            ->get()
            ->filter(fn (Subscription $subscription) => $subscription->team !== null
                && $subscription->state() === SubscriptionState::Expired
                && ! $subscription->expired_processed_for?->equalTo($subscription->ends_at))
            ->each(function (Subscription $subscription) use ($billing, $queueMembers, &$count): void {
                $team = $subscription->team;

                foreach ($team->members()->get() as $member) {
                    try {
                        $queueMembers->leave($member, $team);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                Notification::send($billing->billingContacts($team), new SubscriptionExpired($subscription));
                $subscription->update(['expired_processed_for' => $subscription->ends_at]);
                $count++;
            });

        return $count;
    }

    private function reconcile(Billing $billing, PaymentGateways $gateways): int
    {
        // Abandoned checkouts: payment pages do not live that long.
        Payment::query()->where('status', PaymentStatus::Pending)
            ->where('created_at', '<', now()->subDays(2))
            ->update(['status' => PaymentStatus::Refused, 'failure_reason' => 'Abandoned']);

        $count = 0;

        Payment::query()->with('team')->where('status', PaymentStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(10))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (Payment $payment) use ($billing, $gateways, &$count): void {
                // A provider no longer configured cannot be asked: leave it to the abandon rule.
                if (! rescue(fn () => $gateways->for($payment->gateway)->isConfigured(), false, false)) {
                    return;
                }

                try {
                    if ($billing->sync($payment)->status !== PaymentStatus::Pending) {
                        $count++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return $count;
    }
}
