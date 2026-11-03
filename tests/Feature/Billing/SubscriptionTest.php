<?php

use App\Data\Plan;
use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionState;
use App\Enums\TeamRole;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Billing\PaymentReceived;
use App\Services\Billing;
use App\Services\CinetPay;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // These tests cover the CinetPay driver; PayDunya has PayDunyaTest.
    config([
        'billing.gateway' => 'cinetpay',
        'billing.cinetpay.api_key' => 'test-key',
        'billing.cinetpay.site_id' => '445566',
        'billing.cinetpay.secret_key' => 'test-secret',
    ]);

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);
});

/** A signed CinetPay notification for a transaction. */
function cinetPayNotification(string $transactionId, array $overrides = []): array
{
    $fields = array_fill_keys(CinetPay::HMAC_FIELDS, '');
    $fields = [...$fields, 'cpm_site_id' => '445566', 'cpm_trans_id' => $transactionId, 'cpm_currency' => 'XOF', ...$overrides];
    $token = hash_hmac('sha256', implode('', array_map(fn ($key) => (string) $fields[$key], CinetPay::HMAC_FIELDS)), 'test-secret');

    return [$fields, $token];
}

function fakeCinetPayCheck(string $status, int $amount, string $currency = 'XOF'): void
{
    Http::fake([
        '*/payment/check' => Http::response([
            'code' => $status === 'ACCEPTED' ? '00' : '627',
            'message' => $status === 'ACCEPTED' ? 'SUCCES' : 'TRANSACTION_CANCEL',
            'data' => ['status' => $status, 'amount' => (string) $amount, 'currency' => $currency, 'payment_method' => 'OM', 'operator_id' => 'OP1'],
        ]),
    ]);
}

function pendingPayment(Team $team, string $plan = 'pro', BillingCycle $cycle = BillingCycle::Monthly, int $extra = 0): Payment
{
    return $team->payments()->create([
        'transaction_id' => Payment::newTransactionId(),
        'gateway' => 'cinetpay',
        'plan' => $plan,
        'billing_cycle' => $cycle,
        'extra_agents' => $extra,
        'amount' => Plan::findOrFail($plan)->price($cycle, $extra),
        'currency' => 'XOF',
        'status' => PaymentStatus::Pending,
    ]);
}

it('starts every new team on the free trial', function () {
    $subscription = $this->team->subscription;

    expect($subscription->plan)->toBe(config('billing.trial_plan'))
        ->and($subscription->on_trial)->toBeTrue()
        ->and($subscription->state())->toBe(SubscriptionState::Trialing)
        ->and($subscription->ends_at->isSameDay(now()->addDays(config('billing.trial_days'))))->toBeTrue();
});

it('renders the pricing page from the configured plans', function () {
    $this->get('/pricing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('marketing/Pricing')
        ->has('plans', count(config('billing.plans')))
        ->where('plans.0.code', array_key_first(config('billing.plans')))
        ->where('plans.0.monthlyPrice', 10000)
        ->where('plans.3.code', 'pro')
        ->where('plans.3.monthlyPrice', 150000)
        ->where('plans.3.annualPrice', 1500000)
    );
});

it('shows billing to owners and admins only', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($this->team);

    $this->actingAs($this->owner)->get('/settings/billing')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Billing')->where('subscription.state', 'trialing'));

    $this->actingAs($member)->get('/settings/billing')->assertForbidden();
});

it('creates a pending payment and sends the customer to CinetPay', function () {
    Http::fake([
        '*/payment' => Http::response(['code' => '201', 'message' => 'CREATED', 'data' => ['payment_token' => 't', 'payment_url' => 'https://checkout.cinetpay.com/payment/abc']]),
    ]);

    $this->actingAs($this->owner)
        ->post('/settings/billing/checkout', ['plan' => 'entreprise', 'cycle' => 'annual', 'extra_agents' => 2])
        ->assertRedirect('https://checkout.cinetpay.com/payment/abc');

    $payment = $this->team->payments()->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe((375000 + 2 * 7500) * 10)
        ->and($payment->extra_agents)->toBe(2);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payment')
        && $request['amount'] === $payment->amount
        && $request['currency'] === 'XOF'
        && $request['transaction_id'] === $payment->transaction_id
        && str_contains($request['return_url'], '/billing/return/'.$payment->transaction_id));
});

it('refuses a downgrade the team does not fit in', function () {
    foreach (range(1, 6) as $i) {
        $this->team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    }

    Http::fake();

    $this->actingAs($this->owner)
        ->post('/settings/billing/checkout', ['plan' => 'essentiel', 'cycle' => 'monthly'])
        ->assertSessionHasErrors('plan');

    expect($this->team->payments()->count())->toBe(0);
    Http::assertNothingSent();
});

it('activates the period once on a verified notification', function () {
    Notification::fake();
    $trialEnd = $this->team->subscription->ends_at;
    $payment = pendingPayment($this->team, 'pro');
    fakeCinetPayCheck('ACCEPTED', $payment->amount);

    [$fields, $token] = cinetPayNotification($payment->transaction_id);

    $this->post('/webhooks/cinetpay', $fields, ['x-token' => $token])->assertOk();
    $this->post('/webhooks/cinetpay', $fields, ['x-token' => $token])->assertOk();

    $payment->refresh();
    $subscription = $this->team->subscription()->first();

    expect($payment->status)->toBe(PaymentStatus::Accepted)
        ->and($payment->payment_method)->toBe('OM')
        ->and($subscription->on_trial)->toBeFalse()
        ->and($subscription->plan)->toBe('pro')
        // Unused trial days are kept: the paid month follows the trial.
        ->and($subscription->ends_at->toDateString())->toBe($trialEnd->copy()->addMonthNoOverflow()->toDateString());

    Notification::assertSentTimes(PaymentReceived::class, 1);
});

it('rejects notifications with a bad token', function () {
    $payment = pendingPayment($this->team);
    Http::fake();

    [$fields] = cinetPayNotification($payment->transaction_id);

    $this->post('/webhooks/cinetpay', $fields, ['x-token' => 'forged'])->assertUnauthorized();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertNothingSent();
});

it('answers the CinetPay availability probe', function () {
    $this->get('/webhooks/cinetpay')->assertOk();
});

it('never activates on a smaller amount than requested', function () {
    $payment = pendingPayment($this->team, 'entreprise');
    fakeCinetPayCheck('ACCEPTED', 100);

    [$fields, $token] = cinetPayNotification($payment->transaction_id);
    $this->post('/webhooks/cinetpay', $fields, ['x-token' => $token])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refused)
        ->and($this->team->subscription()->first()->plan)->toBe('pro');
});

it('keeps waiting while the customer has not confirmed on the phone', function () {
    $payment = pendingPayment($this->team);
    fakeCinetPayCheck('WAITING_FOR_CUSTOMER', $payment->amount);

    [$fields, $token] = cinetPayNotification($payment->transaction_id);
    $this->post('/webhooks/cinetpay', $fields, ['x-token' => $token])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('checks the payment when the customer comes back and lands on billing', function () {
    Notification::fake();
    $payment = pendingPayment($this->team);
    fakeCinetPayCheck('ACCEPTED', $payment->amount);

    // Cross-site POST from CinetPay: no session, no CSRF token.
    $this->post('/billing/return/'.$payment->transaction_id)
        ->assertRedirect(route('billing.index', ['payment' => $payment->transaction_id]));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Accepted);
});

it('converts unused paid time when changing plan', function () {
    $this->freezeSecond();
    $subscription = $this->team->subscription;
    $subscription->update(['plan' => 'essentiel', 'on_trial' => false, 'billing_cycle' => BillingCycle::Monthly, 'ends_at' => now()->addDays(30)]);

    $end = app(Billing::class)->newPeriodEnd($subscription->fresh(), Plan::findOrFail('pro'), BillingCycle::Monthly, 0, now());

    // 30 days of Essentiel (35 000/30 per day) = 7 days of Pro (150 000/30 per day).
    expect((int) round(now()->addMonthNoOverflow()->diffInDays($end)))->toBe(7);
});

it('extends a renewal from the current end date', function () {
    $this->freezeSecond();
    $subscription = $this->team->subscription;
    $subscription->update(['plan' => 'pro', 'on_trial' => false, 'ends_at' => now()->addDays(3)]);

    $end = app(Billing::class)->newPeriodEnd($subscription->fresh(), Plan::findOrFail('pro'), BillingCycle::Annual, 0, now());

    expect($end->toDateString())->toBe(now()->addDays(3)->addMonthsNoOverflow(12)->toDateString());
});

// No plan is free today (Starter costs 10 000 F); the support stays for a 0 F plan.
it('switches a trial team to the free plan without payment', function () {
    config(['billing.plans.starter.monthly_price' => 0]);
    Http::fake();

    $this->actingAs($this->owner)
        ->post('/settings/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
        ->assertSessionHasNoErrors();

    $subscription = $this->team->subscription()->first();

    expect($subscription->plan)->toBe('starter')
        ->and($subscription->on_trial)->toBeFalse()
        ->and($subscription->isFree())->toBeTrue()
        ->and($this->team->payments()->count())->toBe(0);

    // A free plan never runs out.
    $this->travel(5)->years();
    expect($subscription->fresh()->state())->toBe(SubscriptionState::Active);
    Http::assertNothingSent();
});

it('keeps a running paid period instead of switching to the free plan', function () {
    config(['billing.plans.starter.monthly_price' => 0]);
    $this->team->subscription()->update(['plan' => 'pro', 'on_trial' => false, 'ends_at' => now()->addDays(20)]);

    $this->actingAs($this->owner)
        ->post('/settings/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
        ->assertSessionHasErrors('plan');

    expect($this->team->subscription()->first()->plan)->toBe('pro');
});

it('refuses the free plan when the team is above its limits', function () {
    config(['billing.plans.starter.monthly_price' => 0]);
    foreach (range(1, 3) as $i) {
        $this->team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    }

    $this->actingAs($this->owner)
        ->post('/settings/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
        ->assertSessionHasErrors('plan');
});

it('closes live supervision on plans without monitoring', function () {
    $this->team->subscription()->update(['plan' => 'starter', 'on_trial' => false]);

    $this->actingAs($this->owner)
        ->get(route('monitoring', ['current_team' => $this->team->slug]))
        ->assertRedirect(route('dashboard', ['current_team' => $this->team->slug]));
});
