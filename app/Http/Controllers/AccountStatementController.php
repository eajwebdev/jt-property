<?php

namespace App\Http\Controllers;

use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\Billing\StatementComposer;
use App\Support\Money;
use App\Support\PdfDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AccountStatementController extends Controller
{
    public function show(Request $request, BillingAccount $account, StatementComposer $composer): Response
    {
        abort_unless($request->user()->canAccessProperty($account->property_id), 403);
        [$from, $to] = $this->dates($request);
        $account->load(['payer:id,full_name,email', 'property:id,name', 'agreements.unit:id,code,building', 'agreements.bed:id,label']);
        $statement = $composer->forPeriod($account, Carbon::parse($from), Carbon::parse($to));
        $today = today()->toDateString();
        // Where the boarder stays now (or stayed last), for the header.
        $rooms = $account->agreements->filter(fn ($a) => $a->end_date === null || $a->end_date->toDateString() >= $today);
        $rooms = ($rooms->isNotEmpty() ? $rooms : $account->agreements->sortByDesc('start_date')->take(1))
            ->map(fn ($a) => $a->unit->label.($a->bed ? ' · '.$a->bed->label : ''))->values()->all();

        // Every payment on this account (all dates), newest first, each with its receipt.
        $payments = Payment::where('billing_account_id', $account->id)->orderByDesc('paid_on')->orderByDesc('id')
            ->get(['id', 'amount_cents', 'paid_on', 'method', 'reference', 'status', 'ack_number', 'receipt_emailed_to'])
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'receipt_number' => $p->ack_number,
                'paid_on' => $p->paid_on->toDateString(),
                'amount_cents' => $p->amount_cents,
                'amount' => Money::format($p->amount_cents),
                'method' => $p->method,
                'reference' => $p->reference,
                'status' => $p->status,
                'account_id' => $account->id,
                'account_no' => $account->account_no,
                'receipt_emailed_to' => $p->receipt_emailed_to,
            ]);

        return Inertia::render('accounts/statement', [
            'account' => [
                'id' => $account->id, 'number' => $account->account_no, 'tenant' => $account->payer->full_name,
                'tenant_id' => $account->payer_tenant_id, 'email' => $account->payer->email, 'property' => $account->property->name,
                'rooms' => $rooms, 'status' => $account->status->value,
            ],
            // For "All time": the first day anything was posted on the account.
            'first_date' => LedgerEntry::where('billing_account_id', $account->id)->min('effective_date')
                ? Carbon::parse(LedgerEntry::where('billing_account_id', $account->id)->min('effective_date'))->toDateString()
                : $from,
            'from' => $from, 'to' => $to,
            'statement' => ['opening_cents' => $statement->openingCents, 'rows' => $statement->rows, 'closing_cents' => $statement->closingCents],
            'payments' => $payments,
            'balance_cents' => (int) LedgerEntry::where('billing_account_id', $account->id)->sum('amount_cents'),
        ]);
    }

    public function download(Request $request, BillingAccount $account, StatementComposer $composer): SymfonyResponse
    {
        abort_unless($request->user()->canAccessProperty($account->property_id), 403);
        [$from, $to] = $this->dates($request);
        $account->load(['payer:id,full_name', 'property:id,name', 'agreements.unit:id,code,building']);
        $statement = $composer->forPeriod($account, Carbon::parse($from), Carbon::parse($to));
        $html = View::make('pdf.account-statement', compact('account', 'statement'))->render();

        return PdfDocument::respond($html, $account->account_no.'-'.$from.'-'.$to.'.pdf',
            fn () => Pdf::html($html)->format('a4')->margins(12, 12, 12, 12)->withBrowsershot(fn ($browser) => $browser->showBackground())->generatePdfContent());
    }

    /** @return array{string,string} */
    private function dates(Request $request): array
    {
        $dates = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $dates['from'] ?? today()->subMonths(3)->toDateString();
        $to = $dates['to'] ?? today()->toDateString();
        abort_if($to < $from, 422, 'End date must be on or after start date.');

        return [$from, $to];
    }
}
