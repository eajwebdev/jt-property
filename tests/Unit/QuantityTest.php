<?php

namespace Tests\Unit;

use App\Support\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    #[DataProvider('readings')]
    public function test_readings_parse_to_thousandths(string $input, int $expected): void
    {
        $this->assertSame($expected, Quantity::toE3($input));
    }

    public static function readings(): array
    {
        return [['42.5', 42500], ['1234', 1234000], ['0', 0], ['0.001', 1], [' 7.25 ', 7250], ['1234.567', 1234567]];
    }

    #[DataProvider('badReadings')]
    public function test_bad_readings_are_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::toE3($input);
    }

    public static function badReadings(): array
    {
        return [[''], ['1.2345'], ['-1'], ['1,000'], ['1e3'], ['abc'], ['.5'], ['12.'], ['9999999999999']];
    }

    public function test_rates_parse_pesos_to_ten_thousandths(): void
    {
        $this->assertSame(115432, Quantity::rateToE4('11.5432'));
        $this->assertSame(110000, Quantity::rateToE4('11'));
        $this->assertSame(110000, Quantity::rateToE4('11.0'));
    }

    public function test_rate_with_more_than_four_decimals_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::rateToE4('11.54321');
    }

    public function test_formatting_shows_fixed_decimals(): void
    {
        $this->assertSame('42.500', Quantity::formatE3(42500));
        $this->assertSame('0.001', Quantity::formatE3(1));
        $this->assertSame('11.5432', Quantity::formatRateE4(115432));
        $this->assertSame('11.0000', Quantity::formatRateE4(110000));
    }
}
