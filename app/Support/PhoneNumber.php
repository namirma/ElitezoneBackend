<?php

namespace App\Support;

/**
 * Phone number utilities.
 *
 * Centralises mobile normalisation so controllers don't each carry
 * a private mobileTo09() copy.
 */
final class PhoneNumber
{
    /**
     * Normalise any Iranian mobile format to the canonical 09xxxxxxxxx form.
     *
     * Handles:
     *   09xxxxxxxxx  → 09xxxxxxxxx  (already canonical)
     *   9xxxxxxxxx   → 09xxxxxxxxx  (missing leading zero)
     *   989xxxxxxxxx → 09xxxxxxxxx  (country code without +)
     *   00989xxxxxxxx→ 09xxxxxxxxx  (international dialling prefix)
     *   +989xxxxxxxx → 09xxxxxxxxx  (international + prefix)
     *
     * Non-digit characters (spaces, dashes, parentheses) are stripped first.
     */
    public static function to09(string $input): string
    {
        // Strip everything that is not a digit
        $digits = preg_replace('/\D/', '', $input);

        if ($digits === null || $digits === '') {
            return $input;
        }

        // 0098… → strip the 00 and treat the rest as 98…
        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 2);
        }

        // 98… → 0…
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        // 9… (10 digits, missing leading zero)
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0'.$digits;
        }

        return $digits;
    }
}
