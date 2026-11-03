<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;

/**
 * A hosted checkout provider (PayDunya, CinetPay…).
 *
 * The app never trusts a redirect or a notification body: a payment is
 * only marked paid from check(), a server-side call to the provider.
 */
interface PaymentGateway
{
    /** Key stored on payments.gateway ("paydunya", "cinetpay"). */
    public function name(): string;

    public function isConfigured(): bool;

    /** Where the provider posts its payment notifications. */
    public function notifyUrl(): string;

    /**
     * Create the checkout.
     *
     * @return array{url: string, reference: string|null} the payment page, and the provider's id for the payment
     */
    public function initialize(Payment $payment, User $customer, string $description, string $returnUrl): array;

    /**
     * The real state of a payment. `status`: ACCEPTED, REFUSED or PENDING.
     *
     * @return array{status: string, amount: int|null, currency: string|null, payment_method: string|null, operator_id: string|null, message: string|null}
     */
    public function check(Payment $payment): array;
}
