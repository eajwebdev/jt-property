<?php

namespace App\Http\Controllers;

use App\Models\BillingAccount;
use App\Services\Billing\StatementComposer;
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
        $account->load(['payer:id,full_name', 'property:id,name']);
        $statement = $composer->forPeriod($account, Carbon::parse($from), Carbon::parse($to));

        return Inertia::render('accounts/statement', [
            'account' => ['id' => $account->id, 'number' => $account->account_no, 'tenant' => $account->payer->full_name, 'property' => $account->property->name],
            'from' => $from, 'to' => $to,
            'statement' => ['opening_cents' => $statement->openingCents, 'rows' => $statement->rows, 'closing_cents' => $statement->closingCents],
        ]);
    }

    public function download(Request $request, BillingAccount $account, StatementComposer $composer): SymfonyResponse
    {
        abort_unless($request->user()->canAccessProperty($account->property_id), 403);
        [$from, $to] = $this->dates($request);
        $account->load(['payer:id,full_name', 'property:id,name']);
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
