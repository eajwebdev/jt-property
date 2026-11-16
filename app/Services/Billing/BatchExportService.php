<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Collection;
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

    /** @param Collection<int, Invoice> $invoices */
    public function combined(Collection $invoices): string
    {
        $style = '';
        $sections = [];

        foreach ($invoices as $invoice) {
            $this->statements->render($invoice);
            $html = $this->statements->html($invoice);
            if ($style === '' && preg_match('/<style>(.*?)<\/style>/s', $html, $match)) {
                $style = $match[1];
            }
            if (! preg_match('/<body>(.*?)<\/body>/s', $html, $match)) {
                throw new RuntimeException('Could not assemble the statements for printing.');
            }
            $sections[] = '<section class="statement-document">'.$match[1].'</section>';
        }

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'.$style
            .'.statement-document { break-after: page; position: relative; } .statement-document:last-child { break-after: auto; } .statement-document .watermark { position: absolute; }'
            .'</style></head><body>'.implode('', $sections).'</body></html>';

        return Pdf::html($html)->format('a4')->margins(8, 0, 16, 0)
            ->withBrowsershot(fn ($browser) => $browser->showBackground()->preferCssPageSize(false))
            ->generatePdfContent();
    }
}
