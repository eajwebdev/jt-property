<?php

namespace App\Http\Controllers;

use App\Enums\BatchItemStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Models\BillingBatch;
use App\Models\BillingBatchItem;
use App\Models\ChargeCategory;
use App\Models\EmailDelivery;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\OneTimeCharge;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Rules\Pesos;
use App\Services\Billing\BatchExportService;
use App\Services\Billing\BillingEmailService;
use App\Services\Billing\BillingPreviewService;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\InvoiceStatusService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PreviewRow;
use App\Services\Billing\ReceivableLedgerService;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** The monthly billing screens: preview, generate, batch results, invoice list and detail, void. */
class BillingController extends Controller
{
    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'overdue', 'paid', 'void'];

    public function __construct(
        private BillingPreviewService $preview,
        private InvoiceGenerationService $generation,
        private InvoiceStatusService $statuses,
        private ReceivableLedgerService $ledger,
        private BillingEmailService $email,
    ) {}

    /**
     * No month chosen: the list of billed months (one card each). With year+month: that month's bills,
     * a dry run for anyone not billed yet. Never writes anything.
     */
    public function index(Request $request): Response
    {
        if (! $request->filled('year') && ! $request->filled('month')) {
            return $this->months($request);
        }

        $user = $request->user();
        $next = today()->addMonthNoOverflow();
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'property_id' => ['nullable', 'integer'],
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['integer', 'distinct', 'exists:billing_accounts,id'],
        ]);
        $year = (int) ($data['year'] ?? $next->year);
        $month = (int) ($data['month'] ?? $next->month);
        $propertyId = ($data['property_id'] ?? null) ?: null;

        $accountIds = isset($data['account_ids']) ? array_map('intval', $data['account_ids']) : null;
        $result = $this->preview->preview($year, $month, $propertyId, null, $user, $accountIds);
        $numbers = collect($result['rows'])->pluck('invoiceNumber')->filter()->all();
        $invoiceIds = Invoice::whereIn('number', $numbers)->pluck('id', 'number');
        // Latest email delivery per bill: sent / queued / skipped / failed (none = never sent).
        $emails = EmailDelivery::whereIn('invoice_id', $invoiceIds->values())->orderBy('id')->get(['invoice_id', 'status'])->keyBy('invoice_id')->map->status;
        // Electricity typed after this month's bill was made: waiting for the next bill.
        $late = OneTimeCharge::whereNull('invoice_id')->where('label', 'like', 'Electricity – %(entered late)')
            ->whereDate('bill_month', Carbon::create($year, $month, 1)->addMonthNoOverflow()->toDateString())
            ->get(['rental_agreement_id', 'amount_cents'])->keyBy('rental_agreement_id');
        // Closed: a later month is already billed (its bills carry this month's balance). View, print and email only.
        $scope = collect($this->properties($request))->pluck('id')->when($propertyId, fn ($c, $id) => $c->intersect([(int) $id]))->values()->all();
        $laterMonth = Invoice::where('status', InvoiceStatus::Finalized)->whereIn('property_id', $scope)
            ->whereDate('period_start', '>=', Carbon::create($year, $month, 1)->addMonthNoOverflow()->toDateString())->max('period_start');
        $closedBy = $laterMonth ? Carbon::parse($laterMonth)->format('F Y') : null;

        return Inertia::render('billing/index', [
            'year' => $year,
            'month' => $month,
            'filters' => ['property_id' => $propertyId, 'account_ids' => $accountIds],
            'properties' => $this->properties($request),
            'rows' => array_map(fn (PreviewRow $r) => $this->previewRow($r, $invoiceIds) + [
                'email_status' => ($id = $invoiceIds[$r->invoiceNumber] ?? null) ? ($emails[$id] ?? null) : null,
            ] + $this->electricityOf($r, $late), $result['rows']),
            'summary' => $result['summary'],
            'canGenerate' => $user->can('owner') && $closedBy === null,
            'closedBy' => $closedBy,
            'canEmail' => $user->can('owner'), // sending statements is allowed even when the month is closed
            'canDeleteMonth' => $user->can('owner') && $closedBy === null && $invoiceIds->isNotEmpty(),
            'operations' => $user->can('owner') ? [
                'queue_connection' => config('queue.default'),
                'pending_jobs' => config('queue.default') === 'database' ? DB::table('jobs')->count() : null,
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'queue_worker_running' => Cache::has('billing.worker.last_seen'),
                'queue_worker_last_seen' => Cache::get('billing.worker.last_seen'),
                'last_scheduler_run' => Cache::get('billing.scheduler.last_run'),
                'mail_driver' => config('mail.default'),
                'pdf_ready' => is_file((string) config('laravel-pdf.browsershot.chrome_path')),
                'zip_ready' => class_exists(\ZipArchive::class),
            ] : null,
        ]);
    }

    /**
     * The tenant's electric bill for a billing month, typed on the billing screen. Stored as one "Electricity"
     * one-time charge labelled with the usage month (the month before: the November bill carries October's EB).
     * Bill not made yet: it goes on this month's bill. Bill already made: it goes on the next bill, "(entered late)".
     * Typing it again before it is billed replaces the amount.
     */
    public function electricity(Request $request): RedirectResponse
    {
        Gate::authorize('owner');
        $data = $request->validate([
            'rental_agreement_id' => ['required', 'integer', 'exists:rental_agreements,id'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'amount' => ['required', 'string', new Pesos('electric bill')],
        ]);
        $agreement = RentalAgreement::with('billingAccount')->findOrFail($data['rental_agreement_id']);
        abort_unless($request->user()->canAccessProperty($agreement->billingAccount->property_id), 403);
        $cents = Money::toCents($data['amount']);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an electric bill above zero.']);
        }

        $month = Carbon::create((int) $data['year'], (int) $data['month'], 1)->startOfDay();
        if (Invoice::where('rental_agreement_id', $agreement->id)->where('status', InvoiceStatus::Finalized)->whereDate('period_start', '>', $month->copy()->endOfMonth()->toDateString())->exists()) {
            throw ValidationException::withMessages(['amount' => 'This month is closed: a later month is already billed. Enter the electric bill on the latest month.']);
        }
        $billed = Invoice::where('rental_agreement_id', $agreement->id)->where('status', InvoiceStatus::Finalized)
            ->whereYear('period_start', $month->year)->whereMonth('period_start', $month->month)->exists();
        $billMonth = $billed ? $month->copy()->addMonthNoOverflow() : $month;
        $label = 'Electricity – '.$month->copy()->subMonthNoOverflow()->format('F Y').($billed ? ' (entered late)' : '');

        $existing = OneTimeCharge::where('rental_agreement_id', $agreement->id)->where('label', $label)->first();
        if ($existing && $existing->invoice_id !== null) {
            throw ValidationException::withMessages(['amount' => 'This electric bill is already on a bill. Use Charges to add a correction.']);
        }

        DB::transaction(function () use ($existing, $agreement, $label, $cents, $billMonth, $request) {
            if ($existing) {
                $old = $existing->amount_cents;
                $existing->update(['amount_cents' => $cents]);
                Audit::log('one_time_charge.updated', $existing, ['old' => $old, 'new' => $cents, 'via' => 'billing electricity']);

                return;
            }
            $charge = OneTimeCharge::create([
                'billing_account_id' => $agreement->billing_account_id, 'rental_agreement_id' => $agreement->id,
                'charge_category_id' => ChargeCategory::where('name', 'Electricity')->value('id'),
                'label' => $label, 'kind' => 'charge', 'amount_cents' => $cents,
                'bill_month' => $billMonth->toDateString(), 'created_by' => $request->user()->id,
            ]);
            Audit::log('one_time_charge.created', $charge, ['amount_cents' => $cents, 'via' => 'billing electricity']);
        });

        return back()->with('success', Money::format($cents).' '.$label.($billed ? ' will be on the next bill.' : ' added to this month\'s bill.'));
    }

    /**
     * Electricity is required on every bill: on_bill (a real electricity line), next_bill (typed after the bill was
     * made, waiting for the next one) or missing. Water is free and never counted.
     *
     * @return array{electricity: string, electricity_cents: int}
     */
    private function electricityOf(PreviewRow $r, Collection $late): array
    {
        $cents = collect($r->lines)->filter(fn ($l) => str_starts_with($l['label'], 'Electricity') && $l['category'] !== 'utility_pending' && $l['amount_cents'] > 0)->sum('amount_cents');
        if ($cents > 0) {
            return ['electricity' => 'on_bill', 'electricity_cents' => (int) $cents];
        }
        if ($late->has($r->agreementId)) {
            return ['electricity' => 'next_bill', 'electricity_cents' => (int) $late[$r->agreementId]->amount_cents];
        }

        return ['electricity' => 'missing', 'electricity_cents' => 0];
    }

    /**
     * "Delete" the latest billed month: every bill of it is voided (with the reason), so the month can be generated
     * again. Older months are closed and refused. Nothing is erased (audit trail and void reason stay).
     */
    public function voidMonth(Request $request): RedirectResponse
    {
        Gate::authorize('owner');
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $ids = collect($this->properties($request))->pluck('id')
            ->when($data['property_id'] ?? null, fn ($c, $id) => $c->intersect([(int) $id]))->values()->all();
        $count = $this->generation->voidMonth((int) $data['year'], (int) $data['month'], $ids, $data['reason'], $request->user());

        return redirect()->route('billing.index', array_filter(['year' => $data['year'], 'month' => $data['month'], 'property_id' => $data['property_id'] ?? null]))
            ->with('success', "$count bills of ".Carbon::create((int) $data['year'], (int) $data['month'], 1)->format('F Y').' were deleted (voided). Payments on them are back as credit. You can generate the month again.');
    }

    /** Every month that has bills: count, billed, still unpaid, paid and emailed bills. Newest first. */
    private function months(Request $request): Response
    {
        $data = $request->validate(['property_id' => ['nullable', 'integer']]);
        $properties = collect($this->properties($request));
        $propertyId = ($data['property_id'] ?? null) ?: null;
        $ids = $propertyId !== null ? $properties->pluck('id')->intersect([(int) $propertyId])->values()->all() : $properties->pluck('id')->all();

        $invoices = Invoice::where('status', InvoiceStatus::Finalized)->whereIn('property_id', $ids)
            ->get(['id', 'billing_account_id', 'period_start', 'current_charges_cents']);
        // Unpaid per bill, in one query: what is left on each bill's ledger charge.
        $unpaid = app(PaymentService::class)->openDebits($invoices->pluck('billing_account_id')->unique()->values()->all())
            ->whereNotNull('invoice_id')->groupBy('invoice_id')->map->sum('remaining_cents');
        $emailed = EmailDelivery::whereIn('invoice_id', $invoices->pluck('id'))->where('status', 'sent')->distinct()->pluck('invoice_id')->flip();

        $months = $invoices->groupBy(fn (Invoice $i) => $i->period_start->format('Y-m'))->map(function (Collection $bills, string $key) use ($unpaid, $emailed) {
            $left = $bills->sum(fn (Invoice $i) => (int) ($unpaid[$i->id] ?? 0));

            return [
                'key' => $key,
                'year' => (int) substr($key, 0, 4),
                'month' => (int) substr($key, 5, 2),
                'bills' => $bills->count(),
                'billed_cents' => (int) $bills->sum('current_charges_cents'),
                'unpaid_cents' => $left,
                'paid_bills' => $bills->filter(fn (Invoice $i) => ($unpaid[$i->id] ?? 0) <= 0)->count(),
                'emailed_bills' => $bills->filter(fn (Invoice $i) => $emailed->has($i->id))->count(),
            ];
        })->sortByDesc('key')->values();

        // The month to start next: the one after the latest billed month, or this month when nothing is billed yet.
        $latest = $months->first();
        $next = $latest ? today()->setDate($latest['year'], $latest['month'], 1)->addMonthNoOverflow() : today()->startOfMonth();

        return Inertia::render('billing/months', [
            'months' => $months,
            'filters' => ['property_id' => $propertyId],
            'properties' => $properties->values(),
            'next' => ['year' => $next->year, 'month' => $next->month],
            'canGenerate' => $request->user()->can('owner'),
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
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['integer', 'distinct', 'exists:billing_accounts,id'],
        ]);

        $batch = $this->generation->generate((int) $data['year'], (int) $data['month'], $request->user(), $data['property_id'] ?? null, null, $data['account_ids'] ?? null);
        $made = $batch->counts['generated'] ?? 0;
        $queued = 0;
        if (Settings::get('auto_send_email')) {
            foreach ($batch->items->where('status', BatchItemStatus::Generated) as $item) {
                if ($item->invoice->property->auto_send_email && $this->email->send($item->invoice)->status === 'queued') {
                    $queued++;
                }
            }
        }

        // Back to the month's bills (now marked Billed), where they can be printed or emailed.
        $left = ($batch->counts['needs_review'] ?? 0) + ($batch->counts['failed'] ?? 0);

        return redirect()->route('billing.index', array_filter(['year' => $data['year'], 'month' => $data['month'], 'property_id' => $data['property_id'] ?? null]))
            ->with('success', "$made bills were created".($queued ? ", $queued statement emails queued" : '').($left ? ". $left left out (see Needs attention)." : '.'));
    }

    public function showBatch(Request $request, BillingBatch $batch): Response
    {
        $user = $request->user();
        $batch->load(['items' => fn ($q) => $q->with(['invoice.emailDeliveries', 'rentalAgreement.unit:id,code,building,property_id', 'rentalAgreement.billingAccount.payer:id,full_name,email'])->orderBy('id')]);

        $items = $batch->items
            ->filter(fn (BillingBatchItem $i) => $user->canAccessProperty($i->rentalAgreement->unit->property_id))
            ->map(fn (BillingBatchItem $i) => [
                'id' => $i->id,
                'status' => $i->status->value,
                'message' => $i->message,
                'tenant' => $i->rentalAgreement->billingAccount->payer->full_name,
                'unit' => $i->rentalAgreement->unit->label,
                'invoice_id' => $i->invoice_id,
                'invoice_number' => $i->invoice?->number,
                'amount_cents' => $i->invoice?->current_charges_cents,
                'email_status' => $i->invoice?->emailDeliveries->last()?->status ?? 'not_sent',
                'email_reason' => $i->invoice?->emailDeliveries->last()?->reason ?? ($i->rentalAgreement->billingAccount->payer->email ? null : 'No email address'),
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
            'canSend' => $user->can('owner'),
        ]);
    }

    public function emailBatch(Request $request, BillingBatch $batch): RedirectResponse
    {
        Gate::authorize('owner');
        $queued = 0;
        foreach ($this->batchInvoices($request, $batch) as $invoice) {
            if ($this->email->send($invoice)->status === 'queued') {
                $queued++;
            }
        }

        return back()->with('success', "$queued statement emails are queued. See each tenant's delivery status below.");
    }

    /** One bill; resend=1 sends it again even when it was already emailed. */
    public function emailInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        Gate::authorize('owner');
        $delivery = $this->email->send($invoice, $request->boolean('resend'));

        return back()->with('success', match ($delivery->status) {
            'queued' => "Statement email for {$invoice->number} queued.",
            'sent' => 'Statement email was already sent. Use "Send again" to send it once more.',
            default => 'Statement not sent: '.$delivery->reason,
        });
    }

    /**
     * Emails every finalized bill of a month (one property or all the owner's) in one click. Bills already
     * emailed are not sent twice; tenants without email / on print delivery are skipped and counted.
     */
    public function emailMonth(Request $request): RedirectResponse
    {
        Gate::authorize('owner');
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]);

        $counts = ['queued' => 0, 'already' => 0, 'skipped' => 0];
        foreach ($this->monthInvoices((int) $data['year'], (int) $data['month'], $data['property_id'] ?? null)->get() as $invoice) {
            $delivery = $this->email->send($invoice);
            $new = $delivery->status === 'queued' && ($delivery->wasRecentlyCreated || $delivery->wasChanged('status'));
            $counts[match (true) {
                $new => 'queued',
                in_array($delivery->status, ['queued', 'sent'], true) => 'already',
                default => 'skipped',
            }]++;
        }

        return back()->with('success', "{$counts['queued']} statement emails queued. {$counts['already']} were already emailed (use Send again on a bill to resend). {$counts['skipped']} skipped (no email address or print delivery).");
    }

    /** Finalized bills whose period starts in the month. */
    private function monthInvoices(int $year, int $month, ?int $propertyId): Builder
    {
        return Invoice::where('status', InvoiceStatus::Finalized)
            ->whereYear('period_start', $year)->whereMonth('period_start', $month)
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->with('billingAccount.payer');
    }

    public function testEmail(Request $request): RedirectResponse
    {
        Gate::authorize('owner');
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return back()->with('success', 'Configure a real mail transport before sending a test.');
        }

        try {
            Mail::raw('Mubarak Boardinghouse billing email test. Your mail settings are working.', function ($message) use ($request): void {
                $message->to($request->user()->email)->subject('Mubarak Boardinghouse mail test');
            });
        } catch (Throwable $exception) {
            Log::error('Billing SMTP test failed', ['exception' => $exception]);

            return back()->with('success', 'The test email failed. Check the application log and SMTP settings.');
        }

        return back()->with('success', 'Test email sent to '.$request->user()->email.'.');
    }

    public function downloadBatch(Request $request, BillingBatch $batch, BatchExportService $exports): StreamedResponse
    {
        $invoices = $this->batchInvoices($request, $batch);
        abort_if($invoices->isEmpty(), 404);
        $path = $exports->zip($invoices, $request->user());

        return response()->streamDownload(function () use ($path): void {
            try {
                readfile($path);
            } finally {
                unlink($path);
            }
        }, sprintf('bills-%04d-%02d.zip', $batch->year, $batch->month), ['Content-Type' => 'application/zip']);
    }

    public function combinedBatch(Request $request, BillingBatch $batch, BatchExportService $exports): StreamedResponse
    {
        $invoices = $this->batchInvoices($request, $batch);
        abort_if($invoices->isEmpty(), 404);
        $bytes = $exports->combined($invoices);

        $filename = sprintf('bills-%04d-%02d.pdf', $batch->year, $batch->month);

        return response()->stream(fn () => print ($bytes), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /** Every finalized statement of a month in one PDF made on the server (needs Chrome; slow the first time). */
    public function monthStatements(Request $request, BatchExportService $exports): StreamedResponse
    {
        [$invoices, $year, $month] = $this->monthStatementInvoices($request);
        $bytes = $exports->combined($invoices);

        $filename = sprintf('statements-%04d-%02d.pdf', $year, $month);

        return response()->stream(fn () => print ($bytes), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /** The same statements as one print-ready page: instant on any hosting, the browser prints or saves the PDF. */
    public function printStatements(Request $request, BatchExportService $exports): \Illuminate\Http\Response
    {
        [$invoices, $year, $month] = $this->monthStatementInvoices($request);
        $title = Carbon::create($year, $month, 1)->format('F Y').' statements';

        return response($exports->printable($invoices, $title))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Finalized bills of the requested month the user may see (one property or all), by bill number. 404 when none.
     *
     * @return array{0: Collection<int, Invoice>, 1: int, 2: int}
     */
    private function monthStatementInvoices(Request $request): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'property_id' => ['nullable', 'integer'],
        ]);
        $scope = collect($this->properties($request))->pluck('id')
            ->when($data['property_id'] ?? null, fn ($c, $id) => $c->intersect([(int) $id]))->values()->all();
        $invoices = $this->monthInvoices((int) $data['year'], (int) $data['month'], null)
            ->whereIn('property_id', $scope)->orderBy('number')->get();
        abort_if($invoices->isEmpty(), 404);

        return [$invoices, (int) $data['year'], (int) $data['month']];
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
            ->with(['billingAccount.payer:id,full_name', 'rentalAgreement.unit:id,code,building', 'property:id,name'])
            ->when($user->role === Role::Staff, fn (Builder $q) => $q->whereIn('property_id', $user->properties()->select('properties.id')))
            ->when($propertyId, fn (Builder $q) => $q->where('property_id', $propertyId))
            ->when($month, fn (Builder $q) => $q->whereYear('period_start', substr($month, 0, 4))->whereMonth('period_start', substr($month, 5, 2)))
            ->orderByDesc('period_start')->orderBy('number')->limit(500)->get()
            ->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'tenant' => $i->billingAccount->payer->full_name,
                'unit' => $i->rentalAgreement->unit->label,
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

        $invoice->load(['lineItems', 'billingAccount.payer:id,full_name', 'rentalAgreement.unit:id,code,building', 'rentalAgreement.bed:id,label', 'property:id,name', 'voider:id,name']);
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
                'unit' => $invoice->rentalAgreement->unit->label.($invoice->rentalAgreement->bed ? ' / '.$invoice->rentalAgreement->bed->label : ''),
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
            'canVoid' => $request->user()->can('owner') && $invoice->status === InvoiceStatus::Finalized && $this->generation->closedBy($invoice) === null,
            'canEmail' => $request->user()->can('owner') && $invoice->status === InvoiceStatus::Finalized,
            'emailDelivery' => $invoice->emailDeliveries()->latest('id')->first()?->only(['status', 'reason', 'sent_at']),
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

    /** @return Collection<int, Invoice> */
    private function batchInvoices(Request $request, BillingBatch $batch): Collection
    {
        $user = $request->user();
        $ids = $batch->items()->whereNotNull('invoice_id')->pluck('invoice_id');

        return Invoice::query()->with('billingAccount.payer')->whereIn('id', $ids)
            ->when($user->role === Role::Staff, fn (Builder $query) => $query->whereIn('property_id', $user->properties()->select('properties.id')))
            ->orderBy('number')->get();
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
            'payments_since_cents' => $r->paymentsSinceCents,
            'estimated_total_cents' => $r->estimatedTotalCents,
            'status' => $r->status,
            'flags' => $r->flags,
            'invoice_number' => $r->invoiceNumber,
            'invoice_id' => $r->invoiceNumber ? ($invoiceIds[$r->invoiceNumber] ?? null) : null,
        ];
    }
}
