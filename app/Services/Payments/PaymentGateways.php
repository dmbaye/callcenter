<?php

namespace App\Services\Payments;

use App\Services\CinetPay;
use InvalidArgumentException;

/**
 * Picks the payment provider: the configured default for new checkouts
 * (billing.gateway), and the one a payment was made with for its checks.
 */
class PaymentGateways
{
    /** @var array<string, class-string<PaymentGateway>> */
    public const DRIVERS = [
        'paydunya' => PayDunya::class,
        'cinetpay' => CinetPay::class,
    ];

    public function default(): PaymentGateway
    {
        return $this->for((string) config('billing.gateway', 'paydunya'));
    }

    public function for(?string $name): PaymentGateway
    {
        $class = self::DRIVERS[$name ?? ''] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown payment gateway [{$name}].");
        }

        return app($class);
    }

    /** Some provider can still be asked about payments (reconciliation). */
    public function anyConfigured(): bool
    {
        foreach (array_keys(self::DRIVERS) as $name) {
            if ($this->for($name)->isConfigured()) {
                return true;
            }
        }

        return false;
    }
}
