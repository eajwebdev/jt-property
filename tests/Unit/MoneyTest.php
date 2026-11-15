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
}
