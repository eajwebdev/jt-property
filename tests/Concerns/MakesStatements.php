<?php

namespace Tests\Concerns;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Support\Carbon;

/** Builds invoices, charges and payments directly (no dependency on invoice generation). */
trait MakesStatements
{
    protected Property $property;

    protected Unit $unit;

    protected BillingAccount $account;

    protected RentalAgreement $agreement;

    protected function setUpStatementFixture(array $tenant = []): void
    {
        $this->property = Property::factory()->create(['name' => 'Riverside Residences', 'address' => '1234 Riverdale Ave., Quezon City']);
        $this->unit = Unit::factory()->for($this->property)->create(['code' => 'A-305']);
        $payer = Tenant::factory()->create(array_merge(['property_id' => $this->property->id, 'full_name' => 'Juan Dela Cruz'], $tenant));
        $this->account = BillingAccount::factory()->for($this->property)->create(['payer_tenant_id' => $payer->id]);
        $this->agreement = RentalAgreement::factory()->for($this->account, 'billingAccount')->for($this->unit)->create();
    }

    /** @param list<array{0:string,1:int}> $lines label => cents; the charge is posted to the ledger for the sum. */
    protected function makeInvoice(string $number, string $start, string $end, array $lines, array $overrides = []): Invoice
    {
        $total = array_sum(array_column($lines, 1));
        $invoice = Invoice::create(array_merge([
            'billing_account_id' => $this->account->id,
            'rental_agreement_id' => $this->agreement->id,
            'property_id' => $this->property->id,
            'number' => $number,
            'period_start' => $start,
            'period_end' => $end,
            'issue_date' => Carbon::parse($start)->subDays(7)->toDateString(),
            'due_date' => Carbon::parse($start)->addDays(4)->toDateString(),
            'status' => InvoiceStatus::Finalized,
            'current_charges_cents' => $total,
            'idempotency_key' => 'k-'.$number,
            'finalized_at' => now(),
        ], $overrides));

        foreach ($lines as $i => [$label, $cents]) {
            InvoiceLineItem::create(['invoice_id' => $invoice->id, 'sort' => $i + 1, 'category' => 'rent', 'label' => $label, 'description' => null, 'amount_cents' => $cents]);
        }
        app(ReceivableLedgerService::class)->post($this->account, LedgerEntryType::Charge, $total, Carbon::parse($start), $invoice);

        return $invoice->fresh();
    }

    protected function pay(int $cents, string $date, string $memo = 'Payment', LedgerEntryType $type = LedgerEntryType::Payment)
    {
        return app(ReceivableLedgerService::class)->post($this->account, $type, -$cents, Carbon::parse($date), $this->account, $memo);
    }

    /** Oct invoice of 1,800 (unpaid) + Nov invoice of 4,150: the acceptance scenario. */
    protected function acceptanceInvoice(): Invoice
    {
        $this->makeInvoice('JT-2026-10-0001', '2026-10-01', '2026-10-31', [['Monthly rent', 180000]]);

        return $this->makeInvoice('JT-2026-11-0001', '2026-11-01', '2026-11-30', [['Monthly rent', 350000], ['Water', 65000]]);
    }
}
