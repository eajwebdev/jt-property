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

    public static function toCents(string|int $pesos): int
    {
        if (is_int($pesos)) {
            return $pesos * 100;
        }

        $input = str_replace(',', '', trim($pesos));

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $input, $m)) {
            throw new InvalidArgumentException("Invalid peso amount: [{$pesos}]");
        }

        $fraction = $m[3] ?? '';

        if (strlen($fraction) > 2) {
            throw new InvalidArgumentException("Peso amount has more than 2 decimal places: [{$pesos}]");
        }

        $cents = (int) $m[2] * 100 + (int) str_pad($fraction, 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }
}
