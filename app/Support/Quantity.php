<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Meter readings and utility rates as integers, never floats:
 * readings in thousandths (42.5 -> 42500), rates in ten-thousandths of a peso (11.5432 -> 115432).
 */
final class Quantity
{
    /** Parse "1234.5" into thousandths. At most 3 decimals, no signs, commas or exponents. */
    public static function toE3(string $input): int
    {
        return self::scale($input, 3);
    }

    /** Parse a peso rate such as "11.5432" into ten-thousandths. At most 4 decimals. */
    public static function rateToE4(string $input): int
    {
        return self::scale($input, 4);
    }

    public static function formatE3(int $e3): string
    {
        return self::format($e3, 3);
    }

    public static function formatRateE4(int $e4): string
    {
        return self::format($e4, 4);
    }

    private static function scale(string $input, int $places): int
    {
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,'.$places.'}))?$/', trim($input), $m)) {
            throw new InvalidArgumentException("Invalid number (up to {$places} decimals): [{$input}]");
        }

        return (int) $m[1] * 10 ** $places + (int) str_pad($m[2] ?? '', $places, '0');
    }

    private static function format(int $scaled, int $places): string
    {
        $factor = 10 ** $places;

        return intdiv($scaled, $factor).'.'.str_pad((string) ($scaled % $factor), $places, '0', STR_PAD_LEFT);
    }
}
