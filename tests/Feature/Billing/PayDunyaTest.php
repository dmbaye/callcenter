<?php

use App\Data\Plan;
use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\TeamRole;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Billing\PaymentReceived;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config([
        'billing.gateway' => 'paydunya',
        'billing.paydunya.mode' => 'test',
        'billing.paydunya.master_key' => 'master-key',
        'billing.paydunya.private_key' => 'test_private_key',
        'billing.paydunya.token' => 'test-token',
    ]);

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);
});

function payDunyaPayment(Team $team, string $token = 'inv_tok_1'): Payment
{
    return $team->payments()->create([
        'transaction_id' => Payment::newTransactionId(),
        'gateway' => 'paydunya',
        'gateway_reference' => $token,
        'plan' => 'pro',
        'billing_cycle' => BillingCycle::Monthly,
        'extra_agents' => 0,
        'amount' => Plan::findOrFail('pro')->price(BillingCycle::Monthly),
        'currency' => 'XOF',
        'status' => PaymentStatus::Pending,
    ]);
}

function fakePayDunyaConfirm(Payment $payment, string $status, ?int $amount = null, string $mode = 'test'): void
{
    Http::fake([
        '*/checkout-invoice/confirm/*' => Http::response([
            'response_code' => '00',
            'response_text' => 'Transaction Found',
            'hash' => hash('sha512', 'master-key'),
            'invoice' => ['token' => $payment->gateway_reference, 'total_amount' => $amount ?? $payment->amount],
            'custom_data' => ['transaction_id' => $payment->transaction_id, 'team_id' => $payment->team_id],
            'mode' => $mode,
            'status' => $status,
            'customer' => ['name' => 'Awa', 'email' => 'awa@example.com', 'phone' => '22376000000'],
        ]),
    ]);
}

/** The IPN body as PayDunya posts it (form-encoded, under `data`). */
function payDunyaIpn(Payment $payment, ?string $hash = null): array
{
    return ['data' => [
        'response_code' => '00',
        'hash' => $hash ?? hash('sha512', 'master-key'),
        'status' => 'completed',
        'invoice' => ['token' => $payment->gateway_reference, 'total_amount' => (string) $payment->amount],
        'custom_data' => ['transaction_id' => $payment->transaction_id],
    ]];
}

it('creates a PayDunya invoice and sends the customer to its payment page', function () {
    Http::fake([
        '*/checkout-invoice/create' => Http::response([
            'response_code' => '00',
            'response_text' => 'https://app.paydunya.com/sandbox-checkout/invoice/inv_tok_9',
            'description' => 'Checkout Invoice Created',
            'token' => 'inv_tok_9',
        ]),
    ]);

    $this->actingAs($this->owner)
        ->post('/settings/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly'])
        ->assertRedirect('https://app.paydunya.com/sandbox-checkout/invoice/inv_tok_9');

    $payment = $this->team->payments()->sole();

    expect($payment->gateway)->toBe('paydunya')
        ->and($payment->gateway_reference)->toBe('inv_tok_9')
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://app.paydunya.com/sandbox-api/v1/checkout-invoice/create')
        && $request->hasHeader('PAYDUNYA-MASTER-KEY', 'master-key')
        && $request['invoice']['total_amount'] === $payment->amount
        && $request['custom_data']['transaction_id'] === $payment->transaction_id
        && str_contains($request['actions']['return_url'], '/billing/return/'.$payment->transaction_id)
        && str_ends_with($request['actions']['callback_url'], '/webhooks/paydunya'));
});

it('activates the plan once from a valid IPN, after confirming with PayDunya', function () {
    Notification::fake();
    $payment = payDunyaPayment($this->team);
    fakePayDunyaConfirm($payment, 'completed');

    $this->post('/webhooks/paydunya', payDunyaIpn($payment))->assertOk();
    $this->post('/webhooks/paydunya', payDunyaIpn($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Accepted)
        ->and($this->team->subscription()->first()->plan)->toBe('pro')
        ->and($this->team->subscription()->first()->on_trial)->toBeFalse();

    Notification::assertSentTimes(PaymentReceived::class, 1);
});

it('rejects an IPN without the master key hash', function () {
    $payment = payDunyaPayment($this->team);
    Http::fake();

    $this->post('/webhooks/paydunya', payDunyaIpn($payment, hash('sha512', 'forged')))->assertUnauthorized();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertNothingSent();
});

it('trusts the confirm API, not the IPN body', function () {
    $payment = payDunyaPayment($this->team);
    fakePayDunyaConfirm($payment, 'pending');

    $this->post('/webhooks/paydunya', payDunyaIpn($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('refuses a payment for less than the price', function () {
    $payment = payDunyaPayment($this->team);
    fakePayDunyaConfirm($payment, 'completed', amount: 100);

    $this->post('/webhooks/paydunya', payDunyaIpn($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refused);
});

it('never activates a sandbox payment in live mode', function () {
    config(['billing.paydunya.mode' => 'live']);
    $payment = payDunyaPayment($this->team);
    fakePayDunyaConfirm($payment, 'completed', mode: 'test');

    $this->post('/webhooks/paydunya', payDunyaIpn($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refused);
});

it('marks a cancelled invoice as refused when the customer comes back', function () {
    $payment = payDunyaPayment($this->team);
    fakePayDunyaConfirm($payment, 'cancelled');

    $this->get('/billing/return/'.$payment->transaction_id.'?token='.$payment->gateway_reference)
        ->assertRedirect();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refused);
});
