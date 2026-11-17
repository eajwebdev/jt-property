<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\StatementSnapshot;
use App\Services\Billing\StatementPdfService;
use App\Support\PdfDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class InvoiceStatementController extends Controller
{
    public function __construct(private StatementPdfService $service) {}

    /** HTML preview of the statement. Never emails or stores anything. */
    public function show(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        return response($this->service->html($invoice, preview: true));
    }

    /**
     * The issued PDF (created on first request, then served from storage). Already issued: always the stored file.
     * Not issued yet and this server cannot make PDFs: a print-ready page of the statement (nothing is stored).
     */
    public function download(Request $request, Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $snapshot = StatementSnapshot::where('invoice_id', $invoice->id)->first();
        if ($snapshot === null && ! PdfDocument::engineAvailable()) {
            return PdfDocument::respond($this->service->html($invoice), $invoice->number.'.pdf', fn () => '', 'A4', '8mm 0 16mm 0');
        }

        $path = $this->service->render($invoice, $request->user());

        return Storage::disk('local')->response($path, $invoice->number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** A new copy of the issued statement, re-rendered from the snapshot totals (print-ready page without a PDF engine). */
    public function copy(Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        return PdfDocument::respond($this->service->html($invoice), $invoice->number.'-copy.pdf', fn () => $this->service->copy($invoice), 'A4', '8mm 0 16mm 0');
    }
}
