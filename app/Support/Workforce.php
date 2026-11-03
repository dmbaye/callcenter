<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Planning times are wall-clock times in the workforce timezone, stored
 * as such (the app runs in UTC). These helpers keep both sides in step.
 */
final class Workforce
{
    public static function timezone(): string
    {
        return (string) config('workforce.timezone', 'Africa/Bamako');
    }

    /** The current wall-clock time of the workforce timezone, as stored. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->shiftTimezone('UTC');
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** Monday of the week of a date. */
    public static function weekStart(CarbonImmutable|string $date): CarbonImmutable
    {
        $date = is_string($date) ? CarbonImmutable::parse($date, 'UTC') : $date;

        return $date->startOfDay()->startOfWeek(CarbonImmutable::MONDAY);
    }

    public static function minutesToHours(int $minutes): string
    {
        $sign = $minutes < 0 ? '−' : '';
        $minutes = abs($minutes);

        return $sign.intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
