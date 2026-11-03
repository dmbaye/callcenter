<?php

namespace App\Services;

use App\Data\Plan;
use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\TeamRole;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Billing\PaymentReceived;
use App\Services\Payments\PaymentGateways;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Prepaid subscription periods bought through a hosted checkout (PayDunya
 * by default, see PaymentGateways).
 *
 * Flow: checkout() writes a pending Payment and returns the provider's
 * page, the notification webhook and the return page both call sync(),
 * which asks that provider for the real status and, once ACCEPTED,
 * activates the period exactly once (row lock + status check).
 */
class Billing
{
    /** Days in a billing month, used to convert unused time on a plan change. */
    private const DAYS_PER_MONTH = 30;

    public function __construct(private PaymentGateways $gateways, private PlanLimits $limits) {}

    /**
     * @param  Closure(Payment): string  $returnUrl
     * @return array{payment: Payment, url: string}
     */
    public function checkout(
        Team $team,
        User $user,
        Plan $plan,
        BillingCycle $cycle,
        int $extraAgents,
        Closure $returnUrl,
    ): array {
        $extraAgents = $plan->allowsExtraAgents() ? max(0, $extraAgents) : 0;

        if ($plan->isFree()) {
            throw new RuntimeException('A free plan has no checkout: use switchToFree().');
        }

        if ($blockers = $this->limits->blockersFor($team, $plan, $extraAgents)) {
            throw ValidationException::withMessages(['plan' => $blockers]);
        }

        $gateway = $this->gateways->default();

        $payment = $team->payments()->create([
            'user_id' => $user->id,
            'transaction_id' => Payment::newTransactionId(),
            'gateway' => $gateway->name(),
            'plan' => $plan->code,
            'billing_cycle' => $cycle,
            'extra_agents' => $extraAgents,
            'amount' => $plan->price($cycle, $extraAgents),
            'currency' => (string) config('billing.currency', 'XOF'),
            'status' => PaymentStatus::Pending,
        ]);

        $description = sprintf('Abonnement %s %s %s', $plan->name, strtolower($cycle->label()), $team->name);

        try {
            $checkout = $gateway->initialize($payment, $user, $description, $returnUrl($payment));
        } catch (RuntimeException $exception) {
            $payment->update(['status' => PaymentStatus::Refused, 'failure_reason' => $exception->getMessage()]);

            throw $exception;
        }

        $payment->update(['payment_url' => $checkout['url'], 'gateway_reference' => $checkout['reference']]);

        return ['payment' => $payment, 'url' => $checkout['url']];
    }

    /**
     * Move the team to a free plan, without payment. Refused while a paid
     * period is still running (its value would be lost): the team switches
     * when it ends. A trial, a grace period or a read-only team can switch
     * at once, as long as it fits the free plan's limits.
     */
    public function switchToFree(Team $team, Plan $plan): Subscription
    {
        if (! $plan->isFree()) {
            throw new RuntimeException("Plan [{$plan->code}] is not free.");
        }

        if ($blockers = $this->limits->blockersFor($team, $plan)) {
            throw ValidationException::withMessages(['plan' => $blockers]);
        }

        return DB::transaction(function () use ($team, $plan): Subscription {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()->where('team_id', $team->id)->lockForUpdate()->first()
                ?? new Subscription(['team_id' => $team->id]);

            $paidAndRunning = $subscription->exists
                && ! $subscription->on_trial
                && ! $subscription->isFree()
                && $subscription->ends_at?->isFuture();

            if ($paidAndRunning) {
                throw ValidationException::withMessages(['plan' => __('Your :plan plan is paid until :date. You can switch to :free when it ends.', [
                    'plan' => $subscription->planDetails()->name,
                    'date' => $subscription->ends_at->translatedFormat('j F Y'),
                    'free' => $plan->name,
                ])]);
            }

            $subscription->team_id = $team->id;
            $subscription->fill([
                'plan' => $plan->code,
                'billing_cycle' => BillingCycle::Monthly,
                'extra_agents' => 0,
                'on_trial' => false,
                // Not used for a free plan (it never expires); kept for the non-null column.
                'ends_at' => now(),
                'reminded_for' => null,
                'expired_processed_for' => null,
            ])->save();

            return $subscription;
        });
    }

    /**
     * Bring a payment in line with its provider. Safe to call any number of
     * times, from any entry point (webhook, return page, reconciliation).
     */
    public function sync(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return $payment;
        }

        $result = $this->gateways->for($payment->gateway)->check($payment);

        $activated = DB::transaction(function () use ($payment, $result): bool {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending) {
                return false;
            }

            if ($result['status'] === 'ACCEPTED') {
                // Never trust a smaller amount or another currency than what was asked.
                if (($result['amount'] !== null && $result['amount'] < $locked->amount)
                    || ($result['currency'] !== null && strtoupper($result['currency']) !== strtoupper($locked->currency))) {
                    $locked->update([
                        'status' => PaymentStatus::Refused,
                        'failure_reason' => 'Amount or currency mismatch: '.$result['amount'].' '.$result['currency'],
                    ]);

                    return false;
                }

                $this->activate($locked, $result['payment_method'], $result['operator_id']);

                return true;
            }

            if ($result['status'] === 'REFUSED') {
                $locked->update([
                    'status' => PaymentStatus::Refused,
                    'payment_method' => $result['payment_method'],
                    'failure_reason' => $result['message'],
                ]);
            }

            // Anything else (WAITING_FOR_CUSTOMER…) stays pending.
            return false;
        });

        $payment->refresh();

        if ($activated) {
            Notification::send($this->billingContacts($payment->team), new PaymentReceived($payment));

            // Plan-dependent dialplan (call recording) follows the new plan right away.
            rescue(fn () => app(AsteriskDialplan::class)->syncTeamRouting($payment->team->fresh()), report: true);
        }

        return $payment;
    }

    /** Must run inside the transaction holding the payment lock. */
    private function activate(Payment $payment, ?string $method, ?string $operatorId): void
    {
        $plan = Plan::findOrFail($payment->plan);

        /** @var Subscription $subscription */
        $subscription = Subscription::query()->where('team_id', $payment->team_id)->lockForUpdate()->first()
            ?? new Subscription(['team_id' => $payment->team_id]);
        $subscription->team_id = $payment->team_id;

        $endsAt = $this->newPeriodEnd($subscription, $plan, $payment->billing_cycle, $payment->extra_agents, now());

        $subscription->fill([
            'plan' => $plan->code,
            'billing_cycle' => $payment->billing_cycle,
            'extra_agents' => $payment->extra_agents,
            'on_trial' => false,
            'ends_at' => $endsAt,
            'reminded_for' => null,
            'expired_processed_for' => null,
        ])->save();

        $payment->update([
            'status' => PaymentStatus::Accepted,
            'payment_method' => $method,
            'operator_id' => $operatorId,
            'paid_at' => now(),
            'period_ends_at' => $endsAt,
        ]);
    }

    /**
     * End of the period being bought.
     *
     * - Same plan and seats while still paid: the new period follows the current one.
     * - Trial: unused trial days are kept on top of the paid period.
     * - Plan change while paid: unused value of the old plan becomes extra
     *   days on the new plan (pro-rata by daily price), the new period starts now.
     */
    public function newPeriodEnd(
        Subscription $current,
        Plan $plan,
        BillingCycle $cycle,
        int $extraAgents,
        CarbonInterface $now,
    ): CarbonInterface {
        $months = $cycle->months();

        if (! $current->exists || $current->ends_at === null || $current->ends_at->lte($now)) {
            return $now->copy()->addMonthsNoOverflow($months);
        }

        if ($current->on_trial) {
            return $current->ends_at->copy()->addMonthsNoOverflow($months);
        }

        if ($current->plan === $plan->code && $current->extra_agents === $extraAgents) {
            return $current->ends_at->copy()->addMonthsNoOverflow($months);
        }

        $oldPlan = $current->planDetails();
        $oldCycle = $current->billing_cycle;
        $oldDaily = $oldPlan->price($oldCycle, $current->extra_agents) / ($oldCycle->months() * self::DAYS_PER_MONTH);
        $newDaily = $plan->price($cycle, $extraAgents) / ($months * self::DAYS_PER_MONTH);

        $remainingDays = $now->diffInSeconds($current->ends_at) / 86400;
        $creditDays = $newDaily > 0 ? (int) floor($remainingDays * $oldDaily / $newDaily) : 0;

        return $now->copy()->addMonthsNoOverflow($months)->addDays($creditDays);
    }

    /**
     * Owners and admins: the people who pay and get billing mail.
     *
     * @return Collection<int, User>
     */
    public function billingContacts(Team $team): Collection
    {
        return $team->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->get();
    }
}
