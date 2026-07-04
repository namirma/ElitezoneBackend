<?php

namespace App\Support;

/**
 * Iranian IBAN (Sheba) utilities.
 *
 * Centralises the MOD-97 checksum check so requests don't each carry
 * a private isValidIranIban() copy.
 */
final class IranIban
{
    /**
     * Validate an Iranian IBAN (format IRxx + 22 digits) via the standard
     * ISO 7064 MOD-97-10 checksum.
     */
    public static function isValid(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));

        if (! preg_match('/^IR\d{24}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (ord($ch) - 55) : $ch;
        }

        $remainder = 0;
        foreach (str_split($numeric, 9) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1;
    }
}
