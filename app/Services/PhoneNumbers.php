<?php

namespace App\Services;

/**
 * Phone numbers are stored as international digits without "+"
 * (22370123456). A national number (8 digits in Mali) gets the country
 * code; "+" and "00" prefixes are removed.
 */
class PhoneNumbers
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (! str_starts_with($raw, '+') && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $national = (int) config('asterisk.campaigns.national_length', 8);

        if (strlen($digits) === $national && ! str_starts_with($raw, '+')) {
            $digits = config('asterisk.campaigns.country_code', '223').$digits;
        }

        $length = strlen($digits);

        return $length >= 8 && $length <= 15 ? $digits : null;
    }

    /** Dial string for the provider: prefix + international digits. */
    public static function dialable(string $phone, ?string $prefix): string
    {
        return ($prefix ?? '').$phone;
    }
}
