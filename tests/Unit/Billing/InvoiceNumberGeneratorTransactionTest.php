<?php

namespace Tests\Unit\Billing;

use App\Models\Property;
use App\Services\Billing\InvoiceNumberGenerator;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\TestCase;

/** No RefreshDatabase here: it would wrap the test in a transaction. */
class InvoiceNumberGeneratorTransactionTest extends TestCase
{
    public function test_next_must_run_inside_a_transaction(): void
    {
        $this->expectException(LogicException::class);
        (new InvoiceNumberGenerator)->next(new Property(['code' => 'RIV']), CarbonImmutable::parse('2026-11-01'));
    }
}
