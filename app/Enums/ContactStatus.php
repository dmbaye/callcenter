<?php

namespace App\Enums;

enum ContactStatus: string
{
    /** Waiting for its (next) attempt; next_attempt_at may hold it back. */
    case Pending = 'pending';
    /** Shown to an agent in preview mode. */
    case Reserved = 'reserved';
    /** A call is being placed or is in progress. */
    case Dialing = 'dialing';
    /** Talked to an agent; waiting for the wrap-up code. */
    case Wrapup = 'wrapup';
    case Callback = 'callback';
    case Completed = 'completed';
    /** Max attempts reached without a final outcome. */
    case Exhausted = 'exhausted';
    case Dnc = 'dnc';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('À appeler'),
            self::Reserved => __('Réservé'),
            self::Dialing => __('En appel'),
            self::Wrapup => __('Qualification'),
            self::Callback => __('Rappel'),
            self::Completed => __('Terminé'),
            self::Exhausted => __('Tentatives épuisées'),
            self::Dnc => __('Ne pas appeler'),
        };
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [
            self::Pending,
            self::Reserved,
            self::Dialing,
            self::Wrapup,
            self::Callback,
        ];
    }
}
