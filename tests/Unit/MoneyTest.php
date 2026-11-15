<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_format_renders_centavos_as_pesos_with_grouping(): void
    {
        $this->assertSame('₱1,234.56', Money::format(123456));
    }

    public function test_format_renders_negative_amounts_with_sign_before_symbol(): void
    {
        $this->assertSame('-₱0.50', Money::format(-50));
    }

    public function test_format_renders_zero(): void
    {
        $this->assertSame('₱0.00', Money::format(0));
    }

    public function test_format_groups_millions(): void
    {
        $this->assertSame('₱1,000,000.00', Money::format(100000000));
    }

    public function test_to_cents_accepts_proper_thousands_grouping(): void
    {
        $this->assertSame(123456789, Money::toCents('1,234,567.89'));
        $this->assertSame(1234567, Money::toCents('12345.67'));
    }

    public function test_to_cents_rejects_comma_used_as_decimal_separator(): void
    {
        // "18,00" must never silently become ₱1,800.00
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('18,00');
    }

    public function test_to_cents_rejects_absurdly_long_amounts(): void
    {
        // 14 peso digits would overflow integer centavos math; cap well above any real rent.
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('12345678901234');
    }

    public function test_to_cents_rejects_misplaced_commas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('1,8,00');
    }

    public function test_to_cents_accepts_comma_grouped_strings(): void
    {
        $this->assertSame(180000, Money::toCents('1,800'));
    }

    public function test_to_cents_parses_decimal_strings_without_float_math(): void
    {
        $this->assertSame(10, Money::toCents('0.1'));
        $this->assertSame(123450, Money::toCents('1,234.5'));
    }

    public function test_to_cents_accepts_integer_pesos(): void
    {
        $this->assertSame(180000, Money::toCents(1800));
    }

    public function test_to_cents_accepts_negative_amounts(): void
    {
        $this->assertSame(-500, Money::toCents('-5'));
    }

    public function test_to_cents_rejects_more_than_two_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents('1.234');
    }

    public function test_to_cents_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents('abc');
    }

    public function test_to_cents_rejects_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents('');
    }

    public function test_round_half_up_div_rounds_exact_halves_up(): void
    {
        $this->assertSame(2, Money::roundHalfUpDiv(3, 2));   // 1.5
        $this->assertSame(1, Money::roundHalfUpDiv(1, 2));   // 0.5
        $this->assertSame(0, Money::roundHalfUpDiv(1, 3));   // 0.33
        $this->assertSame(1, Money::roundHalfUpDiv(2, 3));   // 0.67
        $this->assertSame(5, Money::roundHalfUpDiv(10, 2));  // exact
        $this->assertSame(0, Money::roundHalfUpDiv(0, 7));
        $this->assertSame(1500000, Money::roundHalfUpDiv(1500000 * 30, 30));
        $this->assertSame(500000, Money::roundHalfUpDiv(1500000 * 15, 45)); // 500000.0
    }

    public function test_round_half_up_div_rejects_non_positive_denominator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::roundHalfUpDiv(1, 0);
    }
}
