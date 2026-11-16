<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Models\BillingBatch;
use App\Models\BillingBatchItem;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Services\Billing\BillingPreviewService;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\InvoiceStatusService;
use App\Services\Billing\PreviewRow;
use App\Services\Billing\ReceivableLedgerService;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/** The monthly billing screens: preview, generate, batch results, invoice list and detail, void. */
class BillingController extends Controller
{
    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'overdue', 'paid', 'void'];

    public function __construct(
        private BillingPreviewService $preview,
        private InvoiceGenerationService $generation,
        private InvoiceStatusService $statuses,
        private ReceivableLedgerService $ledger,
    ) {}

    /** Dry run for one month. Never writes anything. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $next = today()->addMonthNoOverflow();
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'property_id' => ['nullable', 'integer'],
        ]);
        $year = (int) ($data['year'] ?? $next->year);
        $month = (int) ($data['month'] ?? $next->month);
        $propertyId = ($data['property_id'] ?? null) ?: null;

        $result = $this->preview->preview($year, $month, $propertyId, null, $user);
        $numbers = collect($result['rows'])->pluck('invoiceNumber')->filter()->all();
        $invoiceIds = Invoice::whereIn('number', $numbers)->pluck('id', 'number');

        return Inertia::render('billing/index', [
            'year' => $year,
            'month' => $month,
            'filters' => ['property_id' => $propertyId],
            'properties' => $this->properties($request),
            'rows' => array_map(fn (PreviewRow $r) => $this->previewRow($r, $invoiceIds), $result['rows']),
            'summary' => $result['summary'],
            'canGenerate' => $user->can('owner'),
        ]);
    }

    /** Creates the month's bills. Safe to repeat: bills that exist are reported as already billed. */
    public function generate(Request $request): RedirectResponse
    {
        Gate::authorize('owner');

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]);

        $batch = $this->generation->generate((int) $data['year'], (int) $data['month'], $request->user(), $data['property_id'] ?? null);
        $made = $batch->counts['generated'] ?? 0;

        return redirect()->route('billing.batches.show', $batch)
            ->with('success', $made === 1 ? '1 bill was created. Nothing was emailed.' : "$made bills were created. Nothing was emailed.");
    }

    public function showBatch(Request $request, BillingBatch $batch): Response
    {
        $user = $request->user();
        $batch->load(['items' => fn ($q) => $q->with(['invoice:id,number,current_charges_cents', 'rentalAgreement.unit:id,code,property_id', 'rentalAgreement.billingAccount.payer:id,full_name'])->orderBy('id')]);

        $items = $batch->items
            ->filter(fn (BillingBatchItem $i) => $user->canAccessProperty($i->rentalAgreement->unit->property_id))
            ->map(fn (BillingBatchItem $i) => [
                'id' => $i->id,
                'status' => $i->status->value,
                'message' => $i->message,
                'tenant' => $i->rentalAgreement->billingAccount->payer->full_name,
                'unit' => $i->rentalAgreement->unit->code,
                'invoice_id' => $i->invoice_id,
                'invoice_number' => $i->invoice?->number,
                'amount_cents' => $i->invoice?->current_charges_cents,
            ])->values();

        return Inertia::render('billing/batch', [
            'batch' => [
                'id' => $batch->id,
                'year' => $batch->year,
                'month' => $batch->month,
                'created_at' => $batch->created_at?->toDateTimeString(),
                'counts' => $items->countBy('status'),
            ],
            'items' => $items,
            'canRetry' => $user->can('owner'),
        ]);
    }

    public function retry(BillingBatchItem $item): RedirectResponse
    {
        Gate::authorize('owner');

        $item = $this->generation->retry($item);

        return redirect()->route('billing.batches.show', $item->billing_batch_id)
            ->with('success', $item->status->value === 'generated' ? 'The bill was created.' : 'That bill still could not be created. See the message below.');
    }

    public function invoices(Request $request): Response
    {
        $user = $request->user();
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'property_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:'.implode(',', self::PAYMENT_STATUSES)],
        ]);
        $month = $data['month'] ?? null;
        $propertyId = ($data['property_id'] ?? null) ?: null;
        $status = $data['status'] ?? null;

        $rows = Invoice::query()
            ->with(['billingAccount.payer:id,full_name', 'rentalAgreement.unit:id,code', 'property:id,name'])
            ->when($user->role === Role::Staff, fn (Builder $q) => $q->whereIn('property_id', $user->properties()->select('properties.id')))
            ->when($propertyId, fn (Builder $q) => $q->where('property_id', $propertyId))
            ->when($month, fn (Builder $q) => $q->whereYear('period_start', substr($month, 0, 4))->whereMonth('period_start', substr($month, 5, 2)))
            ->orderByDesc('period_start')->orderBy('number')->limit(500)->get()
            ->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'tenant' => $i->billingAccount->payer->full_name,
                'unit' => $i->rentalAgreement->unit->code,
                'property' => $i->property->name,
                'period_start' => $i->period_start->toDateString(),
                'period_end' => $i->period_end->toDateString(),
                'due_date' => $i->due_date->toDateString(),
                'current_total_cents' => $i->current_charges_cents,
                'status' => $this->statuses->statusFor($i),
            ])
            ->when($status, fn ($c) => $c->where('status', $status))
            ->values();

        return Inertia::render('billing/invoices', [
            'rows' => $rows,
            'properties' => $this->properties($request),
            'filters' => ['month' => $month, 'property_id' => $propertyId, 'status' => $status],
            'statuses' => self::PAYMENT_STATUSES,
        ]);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        abort_unless($request->user()->canAccessProperty($invoice->property_id), 403);

        $invoice->load(['lineItems', 'billingAccount.payer:id,full_name', 'rentalAgreement.unit:id,code', 'rentalAgreement.bed:id,label', 'property:id,name', 'voider:id,name']);
        $account = $invoice->billingAccount;
        $start = $invoice->period_start;

        // Previous balance leaves this bill out; payments are entries (other than charges) dated inside or after the period.
        $previous = $this->ledger->previousBalance($account, $start, [$invoice->id]);
        $payments = (int) LedgerEntry::where('billing_account_id', $account->id)
            ->whereDate('effective_date', '>=', $start->toDateString())
            ->whereNotIn('type', [LedgerEntryType::Charge->value, LedgerEntryType::Opening->value])
            ->where(fn ($q) => $q->whereNull('invoice_id')->orWhere('invoice_id', '!=', $invoice->id))
            ->sum('amount_cents');
        $current = $invoice->status === InvoiceStatus::Void ? 0 : $invoice->current_charges_cents;

        return Inertia::render('billing/invoice', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $this->statuses->statusFor($invoice),
                'void_reason' => $invoice->void_reason,
                'voided_at' => $invoice->voided_at?->toDateString(),
                'voided_by' => $invoice->voider?->name,
                'period_start' => $start->toDateString(),
                'period_end' => $invoice->period_end->toDateString(),
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'tenant' => $account->payer->full_name,
                'account_no' => $account->account_no,
                'property' => $invoice->property->name,
                'unit' => $invoice->rentalAgreement->unit->code.($invoice->rentalAgreement->bed ? ' / '.$invoice->rentalAgreement->bed->label : ''),
                'batch_id' => $invoice->billing_batch_id,
                'lines' => $invoice->lineItems->map(fn ($l) => [
                    'label' => $l->label,
                    'description' => $l->description,
                    'qty' => $l->qty_e3 !== null ? Quantity::formatE3($l->qty_e3) : null,
                    'rate' => $l->unit_rate_e4 !== null ? Quantity::formatRateE4($l->unit_rate_e4) : null,
                    'amount_cents' => $l->amount_cents,
                ])->values(),
                'current_total_cents' => $invoice->current_charges_cents,
                'previous_balance_cents' => $previous,
                'payments_since_cents' => $payments,
                'statement_total_cents' => $previous + $current + $payments,
            ],
            'canVoid' => $request->user()->can('owner') && $invoice->status === InvoiceStatus::Finalized,
            'statementUrl' => Route::has('invoices.statement') ? route('invoices.statement', $invoice) : null,
            'statementPdfUrl' => Route::has('invoices.statement.pdf') ? route('invoices.statement.pdf', $invoice) : null,
        ]);
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        Gate::authorize('owner');

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->generation->void($invoice, trim($data['reason']), $request->user());

        return redirect()->route('invoices.show', $invoice)->with('success', "Bill {$invoice->number} was voided. The tenant no longer owes it.");
    }

    /** @return list<array{id: int, name: string}> */
    private function properties(Request $request): array
    {
        $user = $request->user();

        return Property::query()
            ->when($user->role === Role::Staff, fn (Builder $q) => $q->whereIn('id', $user->properties()->select('properties.id')))
            ->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name'])->all();
    }

    /** @return array<string, mixed> */
    private function previewRow(PreviewRow $r, $invoiceIds): array
    {
        return [
            'agreement_id' => $r->agreementId,
            'account_id' => $r->accountId,
            'property_id' => $r->propertyId,
            'tenant' => $r->tenantLabel,
            'unit' => $r->unitLabel,
            'period_start' => $r->periodStart,
            'period_end' => $r->periodEnd,
            'issue_date' => $r->issueDate,
            'due_date' => $r->dueDate,
            'lines' => array_map(fn ($l) => [
                'category' => $l['category'],
                'label' => $l['label'],
                'description' => $l['description'],
                'amount_cents' => $l['amount_cents'],
            ], $r->lines),
            'current_charges_cents' => $r->currentChargesCents,
            'previous_balance_cents' => $r->previousBalanceCents,
            'estimated_total_cents' => $r->estimatedTotalCents,
            'status' => $r->status,
            'flags' => $r->flags,
            'invoice_number' => $r->invoiceNumber,
            'invoice_id' => $r->invoiceNumber ? ($invoiceIds[$r->invoiceNumber] ?? null) : null,
        ];
    }
}
