<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Arbitrary-precision money arithmetic (bcmath).
 *
 * All financial amounts in EliteZone are Rials. Computing chained settlement
 * formulas (POO/PTS, VAT splits, commissions) with native PHP floats lets the
 * tiny IEEE-754 representation error of repeating decimals (e.g. 0.1/1.1) build
 * up across multiplications. These helpers keep every intermediate step exact
 * to SCALE decimal places; the caller converts to float only at the very end,
 * where the storage layer (decimal columns) rounds deterministically.
 *
 * Inputs may be int|float|string; everything is normalised to a numeric string.
 */
final class Money
{
    /**
     * Internal working precision. Must be high enough that a small intermediate
     * factor (e.g. the VAT multiplier 1/11 ≈ 0.0909…) multiplied by a billion-Rial
     * amount still resolves to sub-Rial accuracy — 6 places was too few and lost
     * ~100 Rials on such products. 10 places gives ample headroom.
     */
    public const SCALE = 10;

    public static function add(int|float|string $a, int|float|string $b): string
    {
        return bcadd(self::s($a), self::s($b), self::SCALE);
    }

    public static function sub(int|float|string $a, int|float|string $b): string
    {
        return bcsub(self::s($a), self::s($b), self::SCALE);
    }

    public static function mul(int|float|string $a, int|float|string $b): string
    {
        return bcmul(self::s($a), self::s($b), self::SCALE);
    }

    /**
     * @throws \InvalidArgumentException on a zero divisor — a silent '0' here would
     *                                   turn a broken input into a plausible-looking money value (e.g. a zero VAT
     *                                   split, making net == gross) and hide the real bug behind correct-looking
     *                                   ledger rows. Every current caller divides by a non-zero constant
     *                                   ('100') or by (100 + vat%), so this can only fire on genuinely bad data.
     */
    public static function div(int|float|string $a, int|float|string $b): string
    {
        if (bccomp(self::s($b), '0', self::SCALE) === 0) {
            throw new \InvalidArgumentException('Money::div — division by zero.');
        }

        return bcdiv(self::s($a), self::s($b), self::SCALE);
    }

    /** Compare two amounts: -1 if $a < $b, 0 if equal, 1 if $a > $b. */
    public static function cmp(int|float|string $a, int|float|string $b): int
    {
        return bccomp(self::s($a), self::s($b), self::SCALE);
    }

    /** True when $a is strictly less than $b (exact, no float comparison). */
    public static function lt(int|float|string $a, int|float|string $b): bool
    {
        return self::cmp($a, $b) === -1;
    }

    /**
     * Factor (1 + percent/100) — e.g. percentAdd(10) = "1.100000".
     */
    public static function percentAdd(int|float|string $percent): string
    {
        return self::add('1', self::div($percent, '100'));
    }

    /**
     * Factor (1 − percent/100) — e.g. percentSub(0.4) = "0.996000".
     */
    public static function percentSub(int|float|string $percent): string
    {
        return self::sub('1', self::div($percent, '100'));
    }

    /**
     * Final conversion to a float, rounded half-up to $decimals places.
     * Use this only at the boundary where the value leaves the calculation.
     */
    public static function toFloat(int|float|string $value, int $decimals = 2): float
    {
        return round((float) self::s($value), $decimals);
    }

    /**
     * Normalise any numeric to a plain decimal string (no scientific notation),
     * which bcmath requires. Floats are rendered at full needed precision.
     *
     * Public so callers that hold a decimal column value (Eloquent returns those
     * as strings) can normalise it before handing it to add/sub — e.g.
     * WalletTransactionEngine's balance mutations.
     */
    public static function s(int|float|string $v): string
    {
        if (is_string($v)) {
            return $v === '' ? '0' : $v;
        }

        if (is_int($v)) {
            return (string) $v;
        }

        // sprintf avoids float scientific notation (e.g. 1.0E+9) that bcmath rejects.
        return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') ?: '0';
    }
}
