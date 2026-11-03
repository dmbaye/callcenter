<?php

namespace App\Http\Controllers\Billing;

use App\Data\Plan;
use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\OutboundRate;
use App\Models\Payment;
use App\Models\Team;
use App\Services\AsteriskDialplan;
use App\Services\Billing;
use App\Services\OutboundUsage;
use App\Services\Payments\PaymentGateways;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class BillingController extends Controller
{
    /** Public pricing page, built from the same plans the app enforces. */
    public function pricing(): Response
    {
        return Inertia::render('marketing/Pricing', [
            'plans' => array_values(array_map(fn (Plan $plan) => $plan->toArray(), Plan::all())),
            'currency' => config('billing.currency'),
            'trialDays' => (int) config('billing.trial_days'),
            'trialPlan' => (string) config('billing.trial_plan'),
            'annualMonthsCharged' => (int) config('billing.annual_months_charged'),
        ]);
    }

    /** Billing settings of the current team (owner and admins). */
    public function index(Request $request, PlanLimits $limits, PaymentGateways $gateways, OutboundUsage $outbound): Response
    {
        $team = $this->authorizedTeam($request);
        [$from, $to, $month] = OutboundUsage::month($request->string('month')->toString());
        // Teams created with model events muted have no row yet: they start expired and can pay.
        $subscription = $team->subscription()->firstOrCreate([], [
            'plan' => (string) config('billing.trial_plan', 'pro'),
            'billing_cycle' => BillingCycle::Monthly,
            'on_trial' => true,
            'ends_at' => now(),
        ]);
        $state = $subscription->state();

        $returned = $request->string('payment')->toString();
        $returnedPayment = $returned !== ''
            ? $team->payments()->where('transaction_id', $returned)->first()
            : null;

        return Inertia::render('settings/Billing', [
            'plans' => array_values(array_map(fn (Plan $plan) => [
                ...$plan->toArray(),
                'blockers' => $limits->blockersFor($team, $plan),
            ], Plan::all())),
            'subscription' => [
                'plan' => $subscription->plan,
                'planName' => $subscription->planDetails()->name,
                'cycle' => $subscription->billing_cycle->value,
                'extraAgents' => $subscription->extra_agents,
                'onTrial' => $subscription->on_trial,
                'isFree' => $subscription->isFree(),
                'state' => $state->value,
                'stateLabel' => $state->label(),
                'endsAt' => $subscription->ends_at->toIso8601String(),
                'graceEndsAt' => $subscription->on_trial ? null : $subscription->graceEndsAt()->toIso8601String(),
            ],
            'usage' => $limits->usage($team),
            'limits' => $limits->limits($team),
            'payments' => $team->payments()->with('user:id,name')->latest()->limit(20)->get()
                ->map(fn (Payment $payment) => $this->paymentRow($payment))->all(),
            'returnedPayment' => $returnedPayment ? $this->paymentRow($returnedPayment) : null,
            'currency' => config('billing.currency'),
            'annualMonthsCharged' => (int) config('billing.annual_months_charged'),
            'graceDays' => (int) config('billing.grace_days'),
            'paymentsEnabled' => $gateways->default()->isConfigured(),
            // Outside calls of the month, metered from the CDR (null: Asterisk database unreachable).
            'outbound' => rescue(function () use ($outbound, $team, $from, $to, $month) {
                $calls = $outbound->calls($team, $from, $to);

                return [
                    'month' => $month,
                    'months' => OutboundUsage::recentMonths(),
                    'rate' => OutboundRate::current(),
                    'summary' => $outbound->summary($team, $from, $to, $calls),
                    'calls' => array_slice($calls, 0, 100),
                ];
            }, null, report: true),
        ]);
    }

    /** Start a checkout (PayDunya by default) and send the browser to the payment page. */
    public function checkout(Request $request, Billing $billing): HttpResponse
    {
        $team = $this->authorizedTeam($request);

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(Plan::all()))],
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'extra_agents' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $plan = Plan::findOrFail($data['plan']);

        // Free plan: no payment page.
        if ($plan->isFree()) {
            $billing->switchToFree($team, $plan);
            rescue(fn () => app(AsteriskDialplan::class)->syncTeamRouting($team->fresh()), report: true);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Your organization is now on the :plan plan.', ['plan' => $plan->name])]);

            return back(303);
        }

        try {
            $checkout = $billing->checkout(
                $team,
                $request->user(),
                $plan,
                BillingCycle::from($data['cycle']),
                (int) ($data['extra_agents'] ?? 0),
                fn (Payment $payment) => route('billing.return', ['transaction' => $payment->transaction_id]),
            );
        } catch (RuntimeException $exception) {
            report($exception);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The payment page could not be opened. Please try again in a moment.')]);

            return back(303);
        }

        return Inertia::location($checkout['url']);
    }

    /** Where the provider sends the customer back (GET or cross-site POST, no session). */
    public function return(string $transaction, Billing $billing, PaymentGateways $gateways): RedirectResponse
    {
        $payment = Payment::query()->where('transaction_id', $transaction)->first();

        if ($payment !== null && $payment->status === PaymentStatus::Pending && $gateways->for($payment->gateway)->isConfigured()) {
            try {
                $billing->sync($payment);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->route('billing.index', $payment ? ['payment' => $payment->transaction_id] : [], 303);
    }

    /** @return array<string, mixed> */
    private function paymentRow(Payment $payment): array
    {
        return [
            'transactionId' => $payment->transaction_id,
            'planName' => $payment->planDetails()?->name ?? $payment->plan,
            'cycle' => $payment->billing_cycle->value,
            'extraAgents' => $payment->extra_agents,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'statusLabel' => $payment->status->label(),
            'method' => $payment->payment_method,
            'paidBy' => $payment->user?->name,
            'createdAt' => $payment->created_at->toIso8601String(),
            'periodEndsAt' => $payment->period_ends_at?->toIso8601String(),
        ];
    }

    private function authorizedTeam(Request $request): Team
    {
        $user = $request->user();
        $team = $user?->currentTeam;

        abort_unless($team !== null, 404);
        abort_unless($user->teamRole($team)?->isAtLeast(TeamRole::Admin), 403);

        return $team;
    }
}
