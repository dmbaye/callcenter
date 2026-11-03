<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('En attente'),
            self::Accepted => __('Payé'),
            self::Refused => __('Échoué'),
        };
    }
}
