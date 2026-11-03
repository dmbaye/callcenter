<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayDunya checkout invoices (Orange Money Mali, Moov Money Mali, cards…).
 *
 * create → the customer pays on PayDunya's page → IPN on notifyUrl() and
 * redirect to the return URL (?token=…) → confirm, the only source of truth.
 *
 * @see https://developers.paydunya.com/doc/EN/http_json
 */
class PayDunya implements PaymentGateway
{
    public function name(): string
    {
        return 'paydunya';
    }

    public function isConfigured(): bool
    {
        return filled($this->config('master_key'))
            && filled($this->config('private_key'))
            && filled($this->config('token'));
    }

    public function notifyUrl(): string
    {
        return route('webhooks.paydunya');
    }

    public function initialize(Payment $payment, User $customer, string $description, string $returnUrl): array
    {
        $this->ensureConfigured();

        $invoice = [
            'total_amount' => $payment->amount,
            'description' => $description,
            'customer' => ['name' => $customer->name, 'email' => $customer->email],
        ];

        $channels = $this->channels();

        if ($channels !== []) {
            $invoice['channels'] = $channels;
        }

        $response = $this->http()->post('checkout-invoice/create', [
            'invoice' => $invoice,
            'store' => array_filter([
                'name' => (string) $this->config('store_name', config('app.name')),
                'website_url' => (string) config('app.url'),
                'logo_url' => $this->config('logo_url'),
            ]),
            'actions' => [
                // Cancelling also comes back to the return page, which re-checks the invoice.
                'cancel_url' => $returnUrl,
                'return_url' => $returnUrl,
                'callback_url' => $this->notifyUrl(),
            ],
            'custom_data' => [
                'transaction_id' => $payment->transaction_id,
                'team_id' => $payment->team_id,
            ],
        ]);

        $body = (array) $response->json();
        $url = $body['response_text'] ?? null;

        if (! $response->successful() || (string) ($body['response_code'] ?? '') !== '00' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException(sprintf(
                'PayDunya invoice creation failed: %s %s',
                $body['response_code'] ?? $response->status(),
                is_string($url) ? $url : ($body['description'] ?? ''),
            ));
        }

        return ['url' => $url, 'reference' => isset($body['token']) ? (string) $body['token'] : null];
    }

    public function check(Payment $payment): array
    {
        $this->ensureConfigured();

        if (blank($payment->gateway_reference)) {
            throw new RuntimeException('PayDunya payment '.$payment->transaction_id.' has no invoice token.');
        }

        $response = $this->http()->get('checkout-invoice/confirm/'.rawurlencode((string) $payment->gateway_reference));
        $body = (array) $response->json();

        if (! $response->successful() || (string) ($body['response_code'] ?? '') !== '00') {
            throw new RuntimeException(sprintf(
                'PayDunya confirm failed: %s %s',
                $body['response_code'] ?? $response->status(),
                $body['response_text'] ?? '',
            ));
        }

        // The invoice must be ours (the token is only a lookup key).
        if ((string) data_get($body, 'custom_data.transaction_id', '') !== $payment->transaction_id) {
            return $this->result('REFUSED', $body, 'Invoice does not belong to this payment.');
        }

        // A sandbox payment never activates a live subscription.
        if ($this->live() && (string) ($body['mode'] ?? 'live') !== 'live') {
            return $this->result('REFUSED', $body, 'Test payment received in live mode.');
        }

        $status = match (strtolower((string) ($body['status'] ?? 'pending'))) {
            'completed' => 'ACCEPTED',
            'cancelled', 'canceled', 'failed' => 'REFUSED',
            default => 'PENDING',
        };

        return $this->result($status, $body, $body['fail_reason'] ?? null);
    }

    /**
     * The IPN carries hash = SHA-512 of the master key: proof that it comes
     * from PayDunya (the payment is still confirmed through check()).
     */
    public function verifyNotificationHash(?string $hash): bool
    {
        $masterKey = (string) $this->config('master_key');

        return $masterKey !== '' && is_string($hash) && hash_equals(hash('sha512', $masterKey), strtolower($hash));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: string, amount: int|null, currency: string|null, payment_method: string|null, operator_id: string|null, message: string|null}
     */
    private function result(string $status, array $body, mixed $message): array
    {
        $amount = data_get($body, 'invoice.total_amount');

        return [
            'status' => $status,
            'amount' => is_numeric($amount) ? (int) round((float) $amount) : null,
            // PayDunya invoices are in F CFA only.
            'currency' => 'XOF',
            'payment_method' => ($method = data_get($body, 'payment_method') ?? data_get($body, 'customer.payment_method')) !== null ? (string) $method : null,
            'operator_id' => ($token = data_get($body, 'invoice.token')) !== null ? (string) $token : null,
            'message' => is_string($message) && $message !== '' ? $message : null,
        ];
    }

    /** @return list<string> */
    private function channels(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->config('channels', '')))));
    }

    private function live(): bool
    {
        return $this->config('mode', 'test') === 'live';
    }

    private function http(): PendingRequest
    {
        $base = $this->live()
            ? 'https://app.paydunya.com/api/v1/'
            : 'https://app.paydunya.com/sandbox-api/v1/';

        return Http::baseUrl($base)
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->config('timeout', 15))
            ->withHeaders([
                'PAYDUNYA-MASTER-KEY' => (string) $this->config('master_key'),
                'PAYDUNYA-PRIVATE-KEY' => (string) $this->config('private_key'),
                'PAYDUNYA-TOKEN' => (string) $this->config('token'),
            ]);
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PayDunya is not configured (PAYDUNYA_MASTER_KEY, PAYDUNYA_PRIVATE_KEY, PAYDUNYA_TOKEN).');
        }
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config('billing.paydunya.'.$key, $default);
    }
}
