<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Billing\StatementPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceStatementController extends Controller
{
    public function __construct(private StatementPdfService $service) {}

    /** HTML preview of the statement. Never emails or stores anything. */
    public function show(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        return response($this->service->html($invoice, preview: true));
    }

    /** The issued PDF (created on first request, then served from storage). */
    public function download(Request $request, Invoice $invoice): StreamedResponse
    {
        Gate::authorize('view', $invoice);

        $path = $this->service->render($invoice, $request->user());

        return Storage::disk('local')->response($path, $invoice->number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** A new copy of the issued statement, re-rendered from the snapshot totals. */
    public function copy(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        $bytes = $this->service->copy($invoice);

        return response()->streamDownload(fn () => print ($bytes), $invoice->number.'-copy.pdf', ['Content-Type' => 'application/pdf'], 'inline');
    }
}
