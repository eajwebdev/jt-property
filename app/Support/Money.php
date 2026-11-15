<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored as integer centavos. Never convert to float.
 */
final class Money
{
    public static function format(int $cents): string
    {
        $abs = abs($cents);
        $pesos = number_format(intdiv($abs, 100));
        $centavos = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($cents < 0 ? '-' : '').'₱'.$pesos.'.'.$centavos;
    }

    /** Largest accepted peso amount (13 digits) — far above any rent, far below integer overflow. */
    private const MAX_PESOS = 9_999_999_999_999;

    public static function toCents(string|int $pesos): int
    {
        if (is_int($pesos)) {
            if (abs($pesos) > self::MAX_PESOS) {
                throw new InvalidArgumentException("Peso amount is too large: [{$pesos}]");
            }

            return $pesos * 100;
        }

        // Commas are only accepted as thousands separators ("1,234,567"); "18,00" is rejected, never read as 1800.
        if (! preg_match('/^(-?)(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?$/', trim($pesos), $m)) {
            throw new InvalidArgumentException("Invalid peso amount: [{$pesos}]");
        }

        $whole = str_replace(',', '', $m[2]);
        $fraction = $m[3] ?? '';

        if (strlen($fraction) > 2) {
            throw new InvalidArgumentException("Peso amount has more than 2 decimal places: [{$pesos}]");
        }

        if (strlen(ltrim($whole, '0')) > 13) {
            throw new InvalidArgumentException("Peso amount is too large: [{$pesos}]");
        }

        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }
}
