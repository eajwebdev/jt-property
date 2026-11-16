<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/** A peso amount above zero (or zero when $allowZero), written like 2500, 2,500 or 2,500.50 (parsed by Money::toCents). */
class Pesos implements ValidationRule
{
    public function __construct(private string $label, private bool $allowZero = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $cents = Money::toCents((string) $value);
        } catch (InvalidArgumentException) {
            $cents = null;
        }

        if ($cents === null || $cents < 0 || ($cents === 0 && ! $this->allowZero)) {
            $fail("Enter the {$this->label} in pesos above zero, like 2500 or 2,500.00.");
        }
    }
}
