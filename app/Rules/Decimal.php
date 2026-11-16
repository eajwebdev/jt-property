<?php

namespace App\Rules;

use App\Support\Quantity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/** A meter reading (3 decimals) or a peso rate (4 decimals), parsed by Quantity. Readings may be zero; rates may not. */
class Decimal implements ValidationRule
{
    public function __construct(private int $places, private string $label) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $scaled = $this->places === 4 ? Quantity::rateToE4((string) $value) : Quantity::toE3((string) $value);
        } catch (InvalidArgumentException) {
            $fail("Enter the {$this->label} as a plain number with up to {$this->places} decimals, like ".($this->places === 4 ? '11.5432' : '1234.5').'.');

            return;
        }

        if ($this->places === 4 && $scaled === 0) {
            $fail("The {$this->label} must be above zero.");
        }
    }
}
