<?php

namespace App\Services\Billing;

use App\Enums\BatchItemStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\BillingBatch;
use App\Models\BillingBatchItem;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\OneTimeCharge;
use App\Models\RentalAgreement;
use App\Models\User;
use App\Models\UtilityAllocation;
use App\Models\UtilityBill;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * One-click bill generation. Each agreement is billed in its own transaction under a row lock, so a
 * double click, a second browser tab or a retry can never create a second invoice, charge entry or
 * consumption of a one-time charge. The lines posted are exactly BillingPreviewService::linesForAgreement.
 */
class InvoiceGenerationService
{
    public function __construct(
        private BillingPreviewService $preview,
        private BillingCycleResolver $cycles,
        private ReceivableLedgerService $ledger,
        private InvoiceNumberGenerator $numbers,
        private UtilityAllocationService $utilities,
    ) {}

    /** @param  list<int>|null  $agreementIds */
    public function generate(int $year, int $month, User $by, ?int $propertyId = null, ?array $agreementIds = null): BillingBatch
    {
        $this->authorize($by);

        $batch = BillingBatch::create(['year' => $year, 'month' => $month, 'property_id' => $propertyId, 'created_by' => $by->id, 'status' => 'running']);

        foreach ($this->preview->agreements($year, $month, $propertyId, $agreementIds, null) as $agreement) {
            if ($this->cycles->cycleFor($agreement, $year, $month) === null) {
                continue;
            }
            $item = $batch->items()->create(['rental_agreement_id' => $agreement->id, 'status' => BatchItemStatus::Skipped]);
            $this->process($item, $batch);
        }

        return $this->finish($batch);
    }

    /** Run one item again. Items that already produced or found an invoice are returned unchanged. */
    public function retry(BillingBatchItem $item): BillingBatchItem
    {
        $item = BillingBatchItem::with('billingBatch')->findOrFail($item->id);
        if (in_array($item->status, [BatchItemStatus::Generated, BatchItemStatus::AlreadyBilled], true)) {
            return $item;
        }
        $this->authorize(auth()->user());

        $this->process($item, $item->billingBatch);
        $this->finish($item->billingBatch);

        return $item->refresh();
    }

    /**
     * Void a finalized invoice: reverses its ledger entry, keeps its number and lines. One-time charges it
     * consumed stay attached to it, so a reissue does NOT bill them again; the owner re-adds them if wanted.
     */
    public function void(Invoice $invoice, string $reason, User $by): void
    {
        $this->authorize($by);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of at least 5 characters.']);
        }

        DB::transaction(function () use ($invoice, $reason, $by) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Finalized) {
                throw ValidationException::withMessages(['invoice' => 'This bill is already void.']);
            }

            $entry = LedgerEntry::where('invoice_id', $invoice->id)->whereIn('type', [LedgerEntryType::Charge, LedgerEntryType::Credit])->first();
            if ($entry !== null) {
                $this->ledger->reverse($entry, "Void {$invoice->number}: {$reason}");
            }

            $invoice->update(['status' => InvoiceStatus::Void, 'voided_at' => now(), 'void_reason' => $reason, 'voided_by' => $by->id]);
            Audit::log('invoice.voided', $invoice, ['number' => $invoice->number, 'amount_cents' => $invoice->current_charges_cents, 'reason' => $reason]);
        });
    }

    /** Bill the same agreement and cycle again after a void; the new invoice has a new number and a `:reissue:{n}` key. */
    public function reissue(Invoice $void, User $by): BillingBatch
    {
        $this->authorize($by);
        $void = Invoice::findOrFail($void->id);
        if ($void->status !== InvoiceStatus::Void) {
            throw ValidationException::withMessages(['invoice' => 'Only a void bill can be reissued.']);
        }

        $batch = BillingBatch::create([
            'year' => $void->period_start->year, 'month' => $void->period_start->month,
            'property_id' => $void->property_id, 'created_by' => $by->id, 'status' => 'running',
        ]);
        $item = $batch->items()->create(['rental_agreement_id' => $void->rental_agreement_id, 'status' => BatchItemStatus::Skipped]);
        $this->process($item, $batch);

        return $this->finish($batch);
    }

    private function authorize(?User $by): void
    {
        abort_unless($by?->isOwner(), 403);
    }

    private function process(BillingBatchItem $item, BillingBatch $batch): void
    {
        try {
            [$status, $invoice, $message] = DB::transaction(fn () => $this->bill($item->rental_agreement_id, $batch));
        } catch (UniqueConstraintViolationException) {
            // A concurrent run took the idempotency key between our check and insert.
            $status = BatchItemStatus::AlreadyBilled;
            $invoice = null;
            $message = 'Another run billed this agreement at the same time.';
        } catch (Throwable $e) {
            Log::error('Invoice generation failed', ['agreement_id' => $item->rental_agreement_id, 'batch_id' => $batch->id, 'exception' => $e]);
            $status = BatchItemStatus::Failed;
            $invoice = null;
            $message = $e->getMessage();
        }

        $item->update(['status' => $status, 'invoice_id' => $invoice?->id, 'message' => $message]);
    }

    /** @return array{0: BatchItemStatus, 1: ?Invoice, 2: ?string} */
    private function bill(int $agreementId, BillingBatch $batch): array
    {
        $agreement = RentalAgreement::lockForUpdate()->findOrFail($agreementId);
        $agreement->load(['unit', 'bed', 'replacedBy', 'billingAccount.payer', 'billingAccount.agreements:id,billing_account_id']);

        $cycle = $this->cycles->cycleFor($agreement, $batch->year, $batch->month);
        if ($cycle === null) {
            return [BatchItemStatus::Skipped, null, 'The rental has no billing period in this month.'];
        }

        $start = $cycle->periodStart->toDateString();
        $existing = Invoice::where('rental_agreement_id', $agreement->id)->whereDate('period_start', $start)->get();
        if ($finalized = $existing->firstWhere('status', InvoiceStatus::Finalized)) {
            return [BatchItemStatus::AlreadyBilled, $finalized, null];
        }

        $built = $this->preview->linesForAgreement($agreement, $cycle);
        if ($built['needs_review']) {
            return [BatchItemStatus::NeedsReview, null, 'Needs review: '.implode(', ', $built['flags'])];
        }

        $lines = $built['lines'];
        $total = array_sum(array_column($lines, 'amount_cents'));
        $key = BillingPreviewService::idempotencyKey($agreement->id, $cycle).($existing->isEmpty() ? '' : ':reissue:'.$existing->count());

        $invoice = Invoice::create([
            'billing_account_id' => $agreement->billing_account_id,
            'rental_agreement_id' => $agreement->id,
            'billing_batch_id' => $batch->id,
            'property_id' => $agreement->unit->property_id,
            'number' => $this->numbers->next($agreement->unit->property, $cycle->periodStart),
            'period_start' => $start,
            'period_end' => $cycle->periodEnd->toDateString(),
            'issue_date' => $cycle->issueDate->toDateString(),
            'due_date' => $cycle->dueDate->toDateString(),
            'status' => InvoiceStatus::Finalized,
            'current_charges_cents' => $total,
            'idempotency_key' => $key,
            'finalized_at' => now(),
        ]);

        foreach ($lines as $i => $line) {
            $source = $line['source'] instanceof Model ? $line['source'] : null;
            $invoice->lineItems()->create([
                'sort' => $i + 1, 'category' => $line['category'], 'label' => $line['label'], 'description' => $line['description'],
                'qty_e3' => $line['qty_e3'], 'unit_rate_e4' => $line['unit_rate_e4'], 'amount_cents' => $line['amount_cents'],
                'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(),
            ]);
        }

        // Positive total owes more, negative (credits beat charges) is a credit, zero posts nothing.
        if ($total !== 0) {
            $this->ledger->post($agreement->billingAccount, $total > 0 ? LedgerEntryType::Charge : LedgerEntryType::Credit, $total, $cycle->issueDate, $invoice, "Invoice {$invoice->number}");
        }

        $this->consume($lines, $invoice);
        $this->billUtilities($lines, $cycle);

        Audit::log('invoice.generated', $invoice, ['number' => $invoice->number, 'amount_cents' => $total, 'batch_id' => $batch->id, 'idempotency_key' => $key]);

        return [BatchItemStatus::Generated, $invoice, null];
    }

    /** Mark each one-time charge on the invoice as used; a charge that was taken meanwhile aborts the whole invoice. */
    private function consume(array $lines, Invoice $invoice): void
    {
        foreach ($lines as $line) {
            if ($line['source'] instanceof OneTimeCharge
                && OneTimeCharge::whereKey($line['source']->id)->whereNull('invoice_id')->update(['invoice_id' => $invoice->id]) !== 1) {
                throw new RuntimeException("One-time charge {$line['source']->id} was already billed.");
            }
        }
    }

    /**
     * Freeze each metered/shared utility bill: store every eligible agreement's share first, then mark it
     * billed. A bill already billed (by another agreement of the unit) keeps its stored shares.
     */
    private function billUtilities(array $lines, BillingCycle $cycle): void
    {
        $ids = collect($lines)->pluck('source')->filter(fn ($s) => $s instanceof UtilityBill)->pluck('id')->unique();
        $year = $cycle->periodStart->year;
        $month = $cycle->periodStart->month;

        foreach ($ids as $id) {
            $bill = UtilityBill::with('unit')->lockForUpdate()->findOrFail($id);
            if ($bill->isBilled()) {
                continue;
            }

            $agreements = $this->utilities->eligibleAgreements($bill->unit, $year, $month);
            $cycles = $agreements->mapWithKeys(fn ($a) => [$a->id => $this->cycles->cycleFor($a, $year, $month)])->all();
            $basis = $this->utilities->basis($bill);
            foreach ($this->utilities->allocate($bill, $agreements, $cycles) as $agreementId => $cents) {
                UtilityAllocation::create(['utility_bill_id' => $bill->id, 'rental_agreement_id' => $agreementId, 'amount_cents' => $cents, 'basis' => $basis]);
            }
            $bill->update(['status' => 'billed']);
        }
    }

    private function finish(BillingBatch $batch): BillingBatch
    {
        $counts = array_fill_keys(array_column(BatchItemStatus::cases(), 'value'), 0);
        foreach ($batch->items()->pluck('status') as $status) {
            $counts[$status->value]++;
        }
        $batch->update(['status' => 'done', 'counts' => $counts]);
        Audit::log('billing_batch.finished', $batch, ['year' => $batch->year, 'month' => $batch->month, 'counts' => $counts]);

        return $batch->load('items');
    }
}
