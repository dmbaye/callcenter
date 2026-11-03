<?php

namespace App\Enums;

/** What a wrap-up code does to the contact. */
enum DispositionOutcome: string
{
    /** Goal reached (sale, appointment…): contact completed, counts as success. */
    case Success = 'success';
    /** Done without success (not interested, wrong person…). */
    case Final = 'final';
    /** Call again later (after retry_minutes), within the attempt limit. */
    case Retry = 'retry';
    /** Agent schedules a personal callback at a given time. */
    case Callback = 'callback';
    /** Never call again: number goes to the team DNC list. */
    case Dnc = 'dnc';

    public function label(): string
    {
        return match ($this) {
            self::Success => __('Succès'),
            self::Final => __('Final'),
            self::Retry => __('À rappeler (auto)'),
            self::Callback => __('Rappel planifié'),
            self::Dnc => __('Ne plus appeler'),
        };
    }
}
