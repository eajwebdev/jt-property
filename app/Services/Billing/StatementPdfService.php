<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\StatementSnapshot;
use App\Models\User;
use App\Support\Audit;
use App\Support\PdfDocument;
use App\Support\StatementSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Renders the A4 statement. The first render freezes the numbers and the file in a statement_snapshot;
 * everything after that (downloads, previews, regenerated copies) reads the snapshot, never the live ledger.
 */
class StatementPdfService
{
    public function __construct(private StatementComposer $composer) {}

    /** The statement as issued: the snapshot's numbers when one exists, otherwise live numbers. */
    public function statement(Invoice $invoice): Statement
    {
        $snapshot = StatementSnapshot::where('invoice_id', $invoice->id)->first();

        return $snapshot
            ? $this->composer->fromSnapshot($invoice, $snapshot->totals)
            : $this->composer->forInvoice($invoice);
    }

    public function html(Invoice $invoice, bool $preview = false): string
    {
        return $this->renderHtml($this->statement($invoice), $preview);
    }

    /** Store the PDF (once) and return its path on the private local disk. */
    public function render(Invoice $invoice, ?User $by = null): string
    {
        if ($existing = StatementSnapshot::where('invoice_id', $invoice->id)->first()) {
            return $existing->path;
        }

        return Cache::lock('statement-render:'.$invoice->id, 120)->block(60, function () use ($invoice, $by) {
            if ($existing = StatementSnapshot::where('invoice_id', $invoice->id)->first()) {
                return $existing->path;
            }

            $statement = $this->composer->forInvoice($invoice);
            $bytes = $this->pdf($statement);
            $path = 'statements/'.$invoice->period_start->format('Y').'/'.$invoice->number.'.pdf';

            Storage::disk('local')->put($path, $bytes);

            try {
                StatementSnapshot::create([
                    'invoice_id' => $invoice->id,
                    'path' => $path,
                    'sha256' => hash('sha256', $bytes),
                    'totals' => $statement->totals(),
                    'generated_at' => now(),
                    'generated_by' => $by?->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                return StatementSnapshot::where('invoice_id', $invoice->id)->firstOrFail()->path;
            }

            Audit::log('statement.generated', $invoice, ['path' => $path, 'total_due_cents' => $statement->totalDueCents]);

            return $path;
        });
    }

    /** A fresh PDF of the issued statement, rebuilt from the snapshot totals. Nothing is stored. */
    public function copy(Invoice $invoice): string
    {
        $this->render($invoice);

        return $this->pdf($this->statement($invoice));
    }

    private function pdf(Statement $statement): string
    {
        return PdfDocument::builder($this->renderHtml($statement, false), View::make('pdf.statement-footer', ['s' => $statement])->render())
            ->format('a4')
            ->margins(8, 0, 16, 0)
            ->withBrowsershot(fn ($b) => $b->showBackground()->preferCssPageSize(false))
            ->generatePdfContent();
    }

    private function renderHtml(Statement $statement, bool $preview): string
    {
        return View::make('pdf.statement', [
            's' => $statement,
            'co' => StatementSettings::get(),
            'preview' => $preview,
        ])->render();
    }
}
