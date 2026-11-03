<?php

namespace App\Enums;

/**
 * Derived from the subscription dates, never stored: a paid (or trial)
 * period, then a grace period, then read-only.
 */
enum SubscriptionState: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => __("Période d'essai"),
            self::Active => __('Actif'),
            self::Grace => __('Paiement en retard'),
            self::Expired => __('Expiré (lecture seule)'),
        };
    }

    /** The team can work normally (place calls, add agents, meet). */
    public function isUsable(): bool
    {
        return $this !== self::Expired;
    }
}
