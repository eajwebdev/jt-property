<?php

namespace Tests\Feature\Billing;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Services\Billing\InvoiceNumberGenerator;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Two real database connections (no wrapping test transaction, data committed): connection A holds an open
 * transaction while connection B tries the same row. B must block (lock wait timeout) until A commits,
 * then proceed with the next value / be told the entry was already reversed.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql_second' => config('database.connections.'.config('database.default'))]);
        DB::purge('mysql_second');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_second');
        parent::tearDown();
    }

    /** Run on the second connection with a 1 second lock wait. */
    private function onSecond(callable $fn): mixed
    {
        return DB::usingConnection('mysql_second', function () use ($fn) {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            return $fn();
        });
    }

    public function test_invoice_numbering_serialises_on_the_counter_row_across_two_connections(): void
    {
        $property = Property::factory()->create();
        $month = Carbon::create(2026, 11, 1);
        $numbers = app(InvoiceNumberGenerator::class);

        DB::beginTransaction();
        $first = $numbers->next($property, $month);
        $this->assertSame($property->code.'-202611-0001', $first);

        // Connection B cannot take a number while A's transaction holds the counter row.
        $blocked = false;
        try {
            $this->onSecond(function () use ($numbers, $property, $month) {
                DB::beginTransaction();
                try {
                    $numbers->next($property, $month);
                } finally {
                    DB::rollBack();
                }
            });
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }
        $this->assertTrue($blocked, 'The second connection should have waited on the counter row.');

        DB::commit();

        $second = $this->onSecond(function () use ($numbers, $property, $month) {
            DB::beginTransaction();
            $n = $numbers->next($property, $month);
            DB::commit();

            return $n;
        });
        $this->assertSame($property->code.'-202611-0002', $second);
    }

    public function test_a_charge_reversed_on_one_connection_cannot_be_reversed_again_on_another(): void
    {
        $property = Property::factory()->create();
        $account = BillingAccount::factory()->for($property)->create();
        $agreement = RentalAgreement::factory()->for($account, 'billingAccount')->create();
        $ledger = app(ReceivableLedgerService::class);
        $entry = $ledger->post($account, LedgerEntryType::Charge, 100000, Carbon::create(2026, 11, 1), $agreement);

        DB::beginTransaction();
        $ledger->reverse($entry, 'First reversal');

        $blocked = false;
        try {
            $this->onSecond(fn () => $ledger->reverse($entry, 'Second reversal'));
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }
        $this->assertTrue($blocked, 'The second reverse should wait for the first.');

        DB::commit();

        try {
            $this->onSecond(fn () => $ledger->reverse($entry, 'Second reversal'));
            $this->fail('Entry reversed twice.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entry', $e->errors());
        }
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Reversal)->count());
        $this->assertSame(0, $ledger->balance($account));
    }
}
