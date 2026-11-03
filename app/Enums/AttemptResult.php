<?php

namespace App\Enums;

enum AttemptResult: string
{
    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Failed = 'failed';
    case Machine = 'machine';
    /** Answered, but no agent took it in time (power mode). */
    case Abandoned = 'abandoned';
    /** The agent did not pick up the preview call. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Connected => __('Joint'),
            self::NoAnswer => __('Pas de réponse'),
            self::Busy => __('Occupé'),
            self::Failed => __('Échec réseau'),
            self::Machine => __('Répondeur'),
            self::Abandoned => __('Abandonné'),
            self::Cancelled => __('Annulé'),
        };
    }

    /** Key in campaigns.retry_minutes. */
    public function retryKey(): string
    {
        return $this->value;
    }
}
