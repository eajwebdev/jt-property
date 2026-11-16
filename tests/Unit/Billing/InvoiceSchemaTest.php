<?php

namespace Tests\Unit\Billing;

use App\Enums\BatchItemStatus;
use App\Enums\InvoiceStatus;
use App\Models\BillingBatch;
use App\Models\BillingBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\User;
use App\Services\Billing\InvoiceNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class InvoiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function invoice(array $attrs = []): Invoice
    {
        $agreement = RentalAgreement::factory()->create();
        $account = $agreement->billingAccount;

        return Invoice::create($attrs + [
            'billing_account_id' => $account->id,
            'rental_agreement_id' => $agreement->id,
            'property_id' => $account->property_id,
            'number' => 'TST-202611-'.fake()->unique()->numerify('####'),
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-05',
            'status' => InvoiceStatus::Finalized,
            'current_charges_cents' => 415_000,
            'idempotency_key' => "agreement:{$agreement->id}:cycle:2026-11-01",
            'finalized_at' => now(),
        ]);
    }

    private function nextNumber(Property $property, string $month): string
    {
        return DB::transaction(fn () => app(InvoiceNumberGenerator::class)->next($property, CarbonImmutable::parse($month)));
    }

    public function test_invoice_numbers_are_sequential_per_property_and_month(): void
    {
        $riv = Property::factory()->create(['code' => 'RIV']);
        $hil = Property::factory()->create(['code' => 'HIL']);

        $this->assertSame('RIV-202611-0001', $this->nextNumber($riv, '2026-11-01'));
        $this->assertSame('RIV-202611-0002', $this->nextNumber($riv, '2026-11-20'));
        $this->assertSame('HIL-202611-0001', $this->nextNumber($hil, '2026-11-01'));
        $this->assertSame('RIV-202612-0001', $this->nextNumber($riv, '2026-12-01'));
        $this->assertSame('RIV-202611-0003', $this->nextNumber($riv, '2026-11-01'));
    }

    public function test_idempotency_key_is_unique_in_the_database(): void
    {
        $first = $this->invoice();

        $this->expectException(QueryException::class);
        $this->invoice(['idempotency_key' => $first->idempotency_key]);
    }

    public function test_invoice_number_is_unique_in_the_database(): void
    {
        $first = $this->invoice();

        $this->expectException(QueryException::class);
        $this->invoice(['number' => $first->number]);
    }

    public function test_current_charges_may_be_negative(): void
    {
        $invoice = $this->invoice(['current_charges_cents' => -20_000]);

        $this->assertSame(-20_000, $invoice->fresh()->current_charges_cents);
    }

    public function test_finalized_invoice_can_be_voided_once(): void
    {
        $invoice = $this->invoice();
        $owner = User::factory()->create();

        $invoice->update([
            'status' => InvoiceStatus::Void,
            'voided_at' => now(),
            'void_reason' => 'Wrong rate',
            'voided_by' => $owner->id,
        ]);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertSame('Wrong rate', $invoice->void_reason);

        $this->expectException(LogicException::class);
        $invoice->update(['void_reason' => 'Changed my mind']);
    }

    public function test_finalized_invoice_amounts_cannot_change(): void
    {
        $invoice = $this->invoice();

        $this->expectException(LogicException::class);
        $invoice->update(['current_charges_cents' => 1]);
    }

    public function test_void_cannot_be_combined_with_other_changes(): void
    {
        $invoice = $this->invoice();

        $this->expectException(LogicException::class);
        $invoice->update(['status' => InvoiceStatus::Void, 'voided_at' => now(), 'void_reason' => 'x', 'due_date' => '2026-12-05']);
    }

    public function test_invoices_cannot_be_deleted(): void
    {
        $invoice = $this->invoice();

        $this->expectException(LogicException::class);
        $invoice->delete();
    }

    public function test_line_items_are_append_only(): void
    {
        $line = InvoiceLineItem::create([
            'invoice_id' => $this->invoice()->id,
            'sort' => 1,
            'category' => 'rent',
            'label' => 'Monthly Rental – Room A101',
            'qty_e3' => null,
            'unit_rate_e4' => null,
            'amount_cents' => 350_000,
        ]);
        $this->assertSame(350_000, $line->fresh()->amount_cents);

        try {
            $line->update(['amount_cents' => 1]);
            $this->fail('Line update should throw.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $line->delete();
    }

    public function test_batch_item_is_unique_per_agreement(): void
    {
        $batch = BillingBatch::create([
            'year' => 2026,
            'month' => 11,
            'created_by' => User::factory()->create()->id,
            'status' => 'running',
        ]);
        $agreement = RentalAgreement::factory()->create();

        $item = $batch->items()->create(['rental_agreement_id' => $agreement->id, 'status' => BatchItemStatus::Generated]);
        $this->assertSame(BatchItemStatus::Generated, $item->fresh()->status);
        $this->assertInstanceOf(BillingBatchItem::class, $item);

        $this->expectException(QueryException::class);
        $batch->items()->create(['rental_agreement_id' => $agreement->id, 'status' => BatchItemStatus::Failed]);
    }

    public function test_composite_lookup_indexes_exist(): void
    {
        $columns = fn (string $table) => collect(Schema::getIndexes($table))->pluck('columns')->all();

        $this->assertContains(['unit_id', 'move_in_date'], $columns('occupancies'));
        $this->assertContains(['tenant_id', 'move_in_date'], $columns('occupancies'));
        $this->assertContains(['unit_id', 'start_date'], $columns('rental_agreements'));
        $this->assertContains(['billing_account_id', 'effective_date'], $columns('ledger_entries'));
    }
}
