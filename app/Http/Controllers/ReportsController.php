<?php

namespace App\Http\Controllers;

use App\Enums\DepositStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\SecurityDeposit;
use App\Models\Unit;
use App\Services\Billing\AgingReportService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\StatementOfAccountsReport;
use App\Support\PdfDocument;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function index(Request $request, AgingReportService $aging, PaymentService $payments): Response
    {
        return Inertia::render('reports/index', $this->reportData($request, $aging, $payments));
    }

    public function pdf(Request $request, AgingReportService $aging, PaymentService $payments): SymfonyResponse
    {
        $data = $this->reportData($request, $aging, $payments);
        $html = View::make('pdf.reports', $data)->render();

        return PdfDocument::respond($html, 'reports-'.$data['filters']['month'].'.pdf', fn () => Pdf::html($html)->format('a4')->landscape()->margins(12, 12, 12, 12)
            ->withBrowsershot(fn ($browser) => $browser->showBackground())->generatePdfContent(), 'A4 landscape', '12mm');
    }

    /** All boarders on one sheet, printed on legal paper (8.5" × 14") in landscape. One property per page set. */
    public function statementOfAccounts(Request $request, StatementOfAccountsReport $report): SymfonyResponse
    {
        // as_of: the date the sheet is "as of" (e.g. 2026-10-05). Left empty, each property uses its latest bill's due date.
        $data = $request->validate(['property_id' => ['nullable', 'integer'], 'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $user = $request->user();
        if (isset($data['property_id'])) {
            abort_unless($user->canAccessProperty((int) $data['property_id']), 403);
        }
        $properties = Property::query()
            ->when($user->role === Role::Staff, fn ($query) => $query->whereIn('id', $user->properties()->select('properties.id')))
            ->when(isset($data['property_id']), fn ($query) => $query->where('id', $data['property_id']), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('name')->get();
        abort_if($properties->isEmpty(), 404);

        $asOf = isset($data['as_of']) ? Carbon::parse($data['as_of'])->startOfDay() : null;
        $reports = $properties->map(fn (Property $property) => $report->build($property, $asOf));
        $html = View::make('pdf.statement-of-accounts', [
            'reports' => $reports,
            'preparedBy' => Settings::get('soa_prepared_by'),
            'preparedByTitle' => Settings::get('soa_prepared_by_title'),
        ])->render();
        $footer = '<div style="width:100%; font:7.5pt Arial,sans-serif; text-align:center;">Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>';
        $name = 'statement-of-accounts-'.($properties->count() === 1 ? $properties->first()->code.'-' : '').$reports->first()['as_of']->format('Y-m-d').'.pdf';

        // Match the workbook's legal-size landscape print setup.
        return PdfDocument::respond($html, $name, fn () => PdfDocument::builder($html, config('laravel-pdf.driver') === 'dompdf' ? null : $footer)->paperSize(355.6, 215.9, 'mm')->margins(10, 10, 12, 10)
            ->withBrowsershot(fn ($browser) => $browser->showBackground())->generatePdfContent(), '355.6mm 215.9mm', '10mm 10mm 12mm 10mm');
    }

    /** @return array<string, mixed> */
    private function reportData(Request $request, AgingReportService $aging, PaymentService $payments): array
    {
        $data = $request->validate(['property_id' => ['nullable', 'integer'], 'month' => ['nullable', 'date_format:Y-m']]);
        $user = $request->user();
        $properties = Property::query()->when($user->role === Role::Staff, fn ($query) => $query->whereIn('id', $user->properties()->select('properties.id')))->orderBy('name')->get(['id', 'name']);
        $propertyId = isset($data['property_id']) ? (int) $data['property_id'] : null;
        if ($propertyId !== null) {
            abort_unless($properties->contains('id', $propertyId), 403);
        }
        $month = $data['month'] ?? today()->format('Y-m');
        $start = $month.'-01';
        $end = Carbon::parse($start)->endOfMonth()->toDateString();
        $report = $aging->forUser($user, $propertyId);
        $allowedPropertyIds = $user->role === Role::Staff ? $properties->pluck('id')->all() : null;
        $ids = $propertyId === null ? $properties->pluck('id')->all() : [$propertyId];
        $invoices = Invoice::query()->whereIn('property_id', $ids)->where('status', InvoiceStatus::Finalized)->whereBetween('issue_date', [$start, $end]);
        $receipts = Payment::query()->whereIn('property_id', $ids)->where('status', 'approved')->whereBetween('paid_on', [$start, $end]);
        $billedByProperty = (clone $invoices)->selectRaw('property_id, SUM(current_charges_cents) as cents')->groupBy('property_id')->pluck('cents', 'property_id');
        $collectedByProperty = (clone $receipts)->selectRaw('property_id, SUM(amount_cents) as cents')->groupBy('property_id')->pluck('cents', 'property_id');
        $balancesByProperty = $report['rows']->groupBy('property_id')->map(fn ($rows) => $rows->sum('balance_cents'));
        $units = Unit::whereIn('property_id', $ids)->get(['id', 'property_id', 'capacity']);
        $occupancyByUnit = Occupancy::activeOn($end)->whereIn('unit_id', $units->pluck('id'))->selectRaw('unit_id, COUNT(*) as occupied')->groupBy('unit_id')->pluck('occupied', 'unit_id');
        $capacityByProperty = $units->groupBy('property_id')->map(fn ($rows) => $rows->sum('capacity'));
        $occupiedByProperty = $units->groupBy('property_id')->map(fn ($rows) => $rows->sum(fn (Unit $unit) => (int) ($occupancyByUnit[$unit->id] ?? 0)));
        $propertySummary = $properties->whereIn('id', $ids)->map(fn (Property $property) => [
            'id' => $property->id, 'name' => $property->name,
            'billed_cents' => (int) ($billedByProperty[$property->id] ?? 0),
            'collected_cents' => (int) ($collectedByProperty[$property->id] ?? 0),
            'net_receivable_cents' => (int) ($balancesByProperty[$property->id] ?? 0),
            'capacity' => (int) ($capacityByProperty[$property->id] ?? 0),
            'occupied' => (int) ($occupiedByProperty[$property->id] ?? 0),
        ])->values();
        $agreements = RentalAgreement::query()->whereHas('billingAccount', fn ($query) => $query->whereIn('property_id', $ids))
            ->activeOn($end)->with(['billingAccount.payer:id,full_name', 'unit:id,code,building,property_id'])->get();
        $rents = RentalAgreement::currentRents($agreements, $end);
        $rentRoll = $agreements->map(fn (RentalAgreement $agreement) => [
            'account_id' => $agreement->billing_account_id,
            'account_no' => $agreement->billingAccount->account_no,
            'tenant' => $agreement->billingAccount->payer->full_name,
            'property' => $properties->firstWhere('id', $agreement->unit->property_id)?->name,
            'unit' => $agreement->unit->label,
            'due_day' => $agreement->due_day,
            'rent_cents' => (int) ($rents[$agreement->id] ?? 0),
        ])->values();
        $categories = InvoiceLineItem::query()->whereHas('invoice', fn ($query) => $query->whereIn('property_id', $ids)->where('status', InvoiceStatus::Finalized)->whereBetween('issue_date', [$start, $end]))
            ->selectRaw('category, SUM(amount_cents) as cents')->groupBy('category')->pluck('cents', 'category')->map(fn ($value) => (int) $value);
        $depositsHeld = SecurityDeposit::query()->where('status', DepositStatus::Held)->whereHas('billingAccount', fn ($query) => $query->whereIn('property_id', $ids))->sum('amount_cents');
        $unpaidOpenings = $payments->openDebits($report['rows']->pluck('account_id')->all())->filter(fn ($debit) => $debit->type->value === 'opening')->sum('remaining_cents');

        // Default "as of" for the Statement of Accounts: the latest bill due date of the chosen (or any) property.
        $soa = app(StatementOfAccountsReport::class);
        $soaAsOf = $properties->whereIn('id', $ids)->map(fn (Property $p) => $soa->defaultAsOf($p))->max() ?? today();

        return [
            'filters' => ['property_id' => $propertyId, 'month' => $month],
            'soaAsOf' => $soaAsOf->toDateString(),
            'properties' => $properties,
            'aging' => $report,
            'propertySummary' => $propertySummary,
            'rentRoll' => $rentRoll,
            'chargeCategories' => $categories,
            'depositsHeldCents' => (int) $depositsHeld,
            'unpaidOpeningsCents' => (int) $unpaidOpenings,
            'monthly' => [
                'billed_cents' => (int) Invoice::query()->where('status', InvoiceStatus::Finalized)->whereBetween('issue_date', [$start, $end])->when($allowedPropertyIds !== null, fn ($query) => $query->whereIn('property_id', $allowedPropertyIds))->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))->sum('current_charges_cents'),
                'collected_cents' => (int) Payment::query()->where('status', 'approved')->whereBetween('paid_on', [$start, $end])->when($allowedPropertyIds !== null, fn ($query) => $query->whereIn('property_id', $allowedPropertyIds))->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))->sum('amount_cents'),
            ],
        ];
    }

    public function export(Request $request, AgingReportService $aging): StreamedResponse
    {
        $data = $request->validate(['property_id' => ['nullable', 'integer']]);
        $propertyId = isset($data['property_id']) ? (int) $data['property_id'] : null;
        if ($propertyId !== null) {
            abort_unless($request->user()->canAccessProperty($propertyId), 403);
        }
        $report = $aging->forUser($request->user(), $propertyId);

        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Account', 'Tenant', 'Property', 'Oldest due', 'Current (centavos)', '1-30 (centavos)', '31-60 (centavos)', '61-90 (centavos)', '90+ (centavos)', 'Open total (centavos)', 'Net balance (centavos)']);
            foreach ($report['rows'] as $row) {
                $safe = fn (string $value): string => preg_match('/^\s*[=+@-]/u', $value) ? "'".$value : $value;
                fputcsv($handle, [
                    $safe($row['account_no']), $safe($row['tenant']), $safe($row['property']), $row['oldest_due'],
                    ...array_values($row['buckets']), $row['open_cents'], $row['balance_cents'],
                ]);
            }
            fclose($handle);
        }, 'aging-report-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
