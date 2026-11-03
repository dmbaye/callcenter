<?php

namespace App\Enums;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function months(): int
    {
        return $this === self::Annual ? 12 : 1;
    }

    /** Months actually charged for one period (annual = 10 for 12 by default). */
    public function monthsCharged(): int
    {
        return $this === self::Annual
            ? (int) config('billing.annual_months_charged', 10)
            : 1;
    }

    public function label(): string
    {
        return $this === self::Annual ? 'Annuel' : 'Mensuel';
    }
}
