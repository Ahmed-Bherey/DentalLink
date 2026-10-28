<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for money.
 *
 * WHY THIS EXISTS
 *
 * Every monetary calculation in this application was performed with PHP's
 * native `+` and `-` on values read out of floating-point columns. Binary
 * floating point cannot represent most decimal fractions exactly, so a running
 * balance mutated by repeated read-modify-write accumulates error that never
 * cancels out — the ledger stops reconciling against the payments that built it.
 *
 * Stage 6 converts the columns to DECIMAL, but that alone is not enough: PDO
 * hands DECIMAL back as a string, PHP coerces it to float the moment you apply
 * an arithmetic operator, and the error returns. The storage type and the
 * arithmetic have to be fixed together.
 *
 * This is deliberately small — add, subtract, compare, normalise. It is not a
 * money object, a currency library, or an abstraction layer. It exists so that
 * the ledger has exactly one way to do sums, and that way is exact.
 *
 * All methods take and return numeric STRINGS at SCALE (2 decimal places).
 * Strings in and strings out is the point: the moment a value becomes a float
 * the guarantee is gone.
 */
final class Money
{
    /**
     * Two decimal places: EGP has piastres, and every monetary column in this
     * schema is already scale 2. Changing this would be a currency decision,
     * not a formatting preference.
     */
    public const SCALE = 2;

    /**
     * Coerce any acceptable input to a normalised decimal string.
     *
     * Accepts the string PDO returns for a DECIMAL column, an int, a float from
     * a JSON request body, or null (treated as zero — an absent balance is a
     * zero balance, never an error).
     *
     * Floats are converted through a fixed-point representation rather than a
     * plain cast so that the usual binary artefacts are cut off at the scale we
     * actually store, instead of being carried into the arithmetic.
     */
    public static function of($value): string
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        if (is_float($value) || is_int($value)) {
            $value = sprintf('%.'.(self::SCALE + 4).'F', $value);
        }

        $value = trim((string) $value);

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Not a monetary value: [{$value}].");
        }

        return self::roundHalfUp($value);
    }

    /**
     * Round to SCALE, half away from zero.
     *
     * bcmath TRUNCATES at the requested scale rather than rounding, so a plain
     * bcadd($v, '0', 2) turns 10.999 into 10.99 — quietly losing a piastre on
     * every value with excess precision. Adding half a unit in the value's own
     * direction before truncating gives the arithmetic rounding people expect,
     * and keeps it symmetric for negative amounts (refunds and reversals).
     */
    private static function roundHalfUp(string $value): string
    {
        $half = '0.'.str_repeat('0', self::SCALE).'5';

        $shifted = bccomp($value, '0', self::SCALE + 6) < 0
            ? bcsub($value, $half, self::SCALE + 1)
            : bcadd($value, $half, self::SCALE + 1);

        // Truncation at SCALE now lands on the correctly rounded value.
        return bcadd($shifted, '0', self::SCALE);
    }

    public static function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }

    public static function add($a, $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub($a, $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    /**
     * Multiply a unit amount by a whole quantity.
     *
     * Rounding happens once, here, at the boundary where a line total is formed
     * — not on every intermediate addition. Summing pre-rounded line totals is
     * what keeps an order total equal to the sum of its lines.
     */
    public static function mul($amount, $quantity): string
    {
        return bcmul(self::of($amount), self::of($quantity), self::SCALE);
    }

    /**
     * @return int -1 if $a < $b, 0 if equal, 1 if $a > $b
     */
    public static function cmp($a, $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isNegative($value): bool
    {
        return self::cmp($value, self::zero()) < 0;
    }

    public static function isZero($value): bool
    {
        return self::cmp($value, self::zero()) === 0;
    }

    public static function isPositive($value): bool
    {
        return self::cmp($value, self::zero()) > 0;
    }

    public static function greaterThan($a, $b): bool
    {
        return self::cmp($a, $b) > 0;
    }

    /**
     * Clamp a value at zero.
     *
     * Use only where a negative result is genuinely meaningless. Never use it to
     * paper over a calculation that should not have gone negative in the first
     * place — that hides the defect instead of fixing it, which is exactly what
     * the previous `max(0, $total - $refund)` calls did.
     */
    public static function atLeastZero($value): string
    {
        return self::isNegative($value) ? self::zero() : self::of($value);
    }

    /**
     * Sum an iterable of amounts exactly.
     */
    public static function sum(iterable $amounts): string
    {
        $total = self::zero();

        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    /**
     * Presentation only. Never feed the result back into a calculation.
     */
    public static function format($value): string
    {
        return number_format((float) self::of($value), self::SCALE, '.', ',');
    }
}
