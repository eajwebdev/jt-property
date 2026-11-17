<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use ZipArchive;

class BatchExportService
{
    public function __construct(private StatementPdfService $statements) {}

    /** @param Collection<int, Invoice> $invoices */
    public function zip(Collection $invoices, ?User $by = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jt-bills-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary bills archive.');
        }

        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
            unlink($path);
            throw new RuntimeException('Could not open the bills archive.');
        }

        $completed = false;
        try {
            foreach ($invoices as $invoice) {
                $statementPath = $this->statements->render($invoice, $by);
                $tenant = Str::slug($invoice->billingAccount->payer->full_name) ?: 'tenant';
                if (! $archive->addFile(Storage::disk('local')->path($statementPath), "{$invoice->number}-{$tenant}.pdf")) {
                    throw new RuntimeException("Could not add statement {$invoice->number} to the archive.");
                }
            }
            $completed = true;
        } finally {
            $archive->close();
            if (! $completed) {
                unlink($path);
            }
        }

        return $path;
    }

    /**
     * One PDF of all the statements, made by Chrome on the server. Each statement is issued (snapshot + stored PDF)
     * first, so this is slow for bills never printed before and needs Chrome. On plain web hosting use printable().
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function combined(Collection $invoices): string
    {
        $invoices->each(fn (Invoice $invoice) => $this->statements->render($invoice));

        return Pdf::html($this->assemble($invoices))->format('a4')->margins(8, 0, 16, 0)
            ->withBrowsershot(fn ($browser) => $browser->showBackground()->preferCssPageSize(false))
            ->generatePdfContent();
    }

    /**
     * The same statements as one print-ready web page: no Chrome and no PDF on the server, so it is instant on any
     * hosting. The browser prints it (or saves it as PDF), one statement per A4 page. Shows each statement as issued
     * (snapshot numbers when it has one) and records that it was printed.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function printable(Collection $invoices, string $title): string
    {
        // One commit for all the audit rows instead of one per statement.
        DB::transaction(fn () => $invoices->each(fn (Invoice $invoice) => Audit::log('statement.printed', $invoice, ['via' => 'print all'])));

        $toolbar = '<div class="print-bar"><span><b>'.e($title).'</b> &middot; '.$invoices->count().' statements, one per page.'
            .' In the print window choose <b>Save as PDF</b> to keep a file. Turn on <b>Background graphics</b> for the colours.</span>'
            .'<button type="button" onclick="window.print()">Print / Save as PDF</button></div>';

        // The logo is embedded twice in every statement; on a web page the browser can download it once instead.
        $html = $this->assemble($invoices, $title, $toolbar,
            '@page { size: A4; margin: 8mm 0 10mm; }'
            .'.print-bar { position: sticky; top: 0; z-index: 10; display: flex; gap: 12px; align-items: center; justify-content: space-between;'
            .' padding: 10px 16px; background: #0B3466; color: #fff; font: 14px system-ui, sans-serif; }'
            .'.print-bar button { background: #13A35C; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 600; cursor: pointer; }'
            .'.doc-foot { width: 210mm; margin: 0 auto; padding: 1mm 12mm 0; font-size: 7.5pt; color: #52637A; }'
            .'@media print { .print-bar { display: none; } }',
            '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 300); });</script>');

        return (string) preg_replace('#src="data:image/[a-z]+;base64,[A-Za-z0-9+/=]+"#', 'src="'.asset('branding/mubarak-statement-logo.webp').'"', $html);
    }

    /** Joins the statements' bodies into one HTML document, each starting on a new page. */
    private function assemble(Collection $invoices, string $title = 'Statements', string $before = '', string $extraCss = '', string $after = ''): string
    {
        $style = '';
        $sections = [];

        foreach ($invoices as $invoice) {
            $html = $this->statements->html($invoice);
            if ($style === '' && preg_match('/<style>(.*?)<\/style>/s', $html, $match)) {
                $style = $match[1];
            }
            if (! preg_match('/<body>(.*?)<\/body>/s', $html, $match)) {
                throw new RuntimeException('Could not assemble the statements for printing.');
            }
            $foot = $before === '' ? '' : '<div class="doc-foot">Statement '.e($invoice->number).' &middot; Account '.e($invoice->billingAccount->account_no).'</div>';
            $sections[] = '<section class="statement-document">'.$match[1].$foot.'</section>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($title).'</title><style>'.$style
            .'.statement-document { break-after: page; position: relative; } .statement-document:last-child { break-after: auto; } .statement-document .watermark { position: absolute; }'
            .$extraCss.'</style></head><body>'.$before.implode('', $sections).$after.'</body></html>';
    }
}
