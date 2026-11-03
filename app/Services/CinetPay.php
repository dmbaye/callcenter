<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CinetPay Checkout API v2: initialise a payment, check a transaction and
 * verify the x-token of a notification. Payments are never marked paid
 * from a notification or a redirect alone, always from check().
 *
 * Not available to Malian merchants: kept for Senegal / Côte d'Ivoire
 * (BILLING_GATEWAY=cinetpay) and for payments already made through it.
 *
 * @see https://docs.cinetpay.com/api/1.0-en/checkout/initialisation
 * @see https://docs.cinetpay.com/api/1.0-en/checkout/notification
 * @see https://docs.cinetpay.com/api/1.0-en/checkout/hmac
 */
class CinetPay implements PaymentGateway
{
    /** Notification fields, in the order the x-token HMAC concatenates them. */
    public const HMAC_FIELDS = [
        'cpm_site_id', 'cpm_trans_id', 'cpm_trans_date', 'cpm_amount', 'cpm_currency', 'signature',
        'payment_method', 'cel_phone_num', 'cpm_phone_prefixe', 'cpm_language', 'cpm_version',
        'cpm_payment_config', 'cpm_page_action', 'cpm_custom', 'cpm_designation', 'cpm_error_message',
    ];

    public function name(): string
    {
        return 'cinetpay';
    }

    public function notifyUrl(): string
    {
        return route('webhooks.cinetpay');
    }

    public function isConfigured(): bool
    {
        return filled($this->config('api_key')) && filled($this->config('site_id')) && filled($this->config('secret_key'));
    }

    /**
     * Create the checkout and return the CinetPay payment page URL.
     */
    public function initialize(Payment $payment, User $customer, string $description, string $returnUrl): array
    {
        $notifyUrl = $this->notifyUrl();
        $this->ensureConfigured();

        [$firstName, $lastName] = $this->splitName($customer->name);

        $response = $this->http()->post('/payment', array_filter([
            'apikey' => $this->config('api_key'),
            'site_id' => $this->config('site_id'),
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            // No special characters (#,/,$,_,&) in the description.
            'description' => trim((string) preg_replace('/[^\pL\pN \-.]/u', ' ', $description)),
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'channels' => $this->config('channels', 'ALL'),
            'lang' => $this->config('lang', 'fr'),
            'metadata' => 'team:'.$payment->team_id,
            'customer_id' => (string) $customer->id,
            'customer_name' => $lastName,
            'customer_surname' => $firstName,
            'customer_email' => $customer->email,
            'customer_address' => $this->config('customer_city', 'Bamako'),
            'customer_city' => $this->config('customer_city', 'Bamako'),
            'customer_country' => $this->config('customer_country', 'ML'),
            'customer_state' => $this->config('customer_country', 'ML'),
            'customer_zip_code' => '00000',
        ], fn ($value) => $value !== '' && $value !== null));

        $body = (array) $response->json();
        $url = data_get($body, 'data.payment_url');

        if (! $response->successful() || (string) ($body['code'] ?? '') !== '201' || ! is_string($url)) {
            throw new RuntimeException(sprintf(
                'CinetPay initialisation failed: %s %s',
                $body['code'] ?? $response->status(),
                $body['description'] ?? $body['message'] ?? '',
            ));
        }

        return ['url' => $url, 'reference' => null];
    }

    /**
     * Ask CinetPay for the real state of a transaction. `status` is
     * ACCEPTED, REFUSED or a pending value (WAITING_FOR_CUSTOMER, PENDING…).
     *
     * @return array{status: string, amount: int|null, currency: string|null, payment_method: string|null, operator_id: string|null, message: string|null}
     */
    public function check(Payment $payment): array
    {
        $this->ensureConfigured();
        $transactionId = $payment->transaction_id;

        $response = $this->http()->post('/payment/check', [
            'apikey' => $this->config('api_key'),
            'site_id' => $this->config('site_id'),
            'transaction_id' => $transactionId,
        ]);

        $body = (array) $response->json();
        $data = (array) ($body['data'] ?? []);

        if ($data === [] && ! $response->successful()) {
            throw new RuntimeException('CinetPay check failed: HTTP '.$response->status());
        }

        return [
            'status' => strtoupper((string) ($data['status'] ?? 'PENDING')),
            'amount' => isset($data['amount']) ? (int) $data['amount'] : null,
            'currency' => isset($data['currency']) ? (string) $data['currency'] : null,
            'payment_method' => isset($data['payment_method']) ? (string) $data['payment_method'] : null,
            'operator_id' => isset($data['operator_id']) ? (string) $data['operator_id'] : null,
            'message' => isset($body['message']) ? (string) $body['message'] : null,
        ];
    }

    /**
     * Verify the x-token header of a notification (HMAC-SHA256 of the
     * concatenated fields, keyed with the merchant secret key).
     *
     * @param  array<string, mixed>  $fields
     */
    public function verifyNotification(array $fields, ?string $token): bool
    {
        $secret = (string) $this->config('secret_key');

        if ($secret === '' || $token === null || $token === '') {
            return false;
        }

        $data = implode('', array_map(fn (string $key) => (string) ($fields[$key] ?? ''), self::HMAC_FIELDS));

        return hash_equals(hash_hmac('sha256', $data, $secret), $token);
    }

    /** @return array{0: string, 1: string} first name, last name */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = array_shift($parts) ?? $name;

        return [$first, $parts === [] ? $first : implode(' ', $parts)];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->config('api_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->config('timeout', 15));
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('CinetPay is not configured (CINETPAY_API_KEY, CINETPAY_SITE_ID, CINETPAY_SECRET_KEY).');
        }
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config('billing.cinetpay.'.$key, $default);
    }
}
