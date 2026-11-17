<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * One way to hand a document to the browser: a real PDF when the server can make one (Chrome via Browsershot, or a
 * remote driver such as Cloudflare), otherwise the same HTML as a print-ready page. Plain shared hosting has no
 * Node/Chrome; there the browser prints the page or saves it as PDF instead of the app failing with a 500.
 */
class PdfDocument
{
    /**
     * @param  Closure(): string  $makePdf  builds the PDF bytes (only called when an engine is available)
     * @param  string  $pageSize  CSS @page size, e.g. "A4", "A4 landscape", "330.2mm 215.9mm"
     * @param  string  $margins  CSS @page margin, e.g. "12mm"
     */
    public static function respond(string $html, string $filename, Closure $makePdf, string $pageSize = 'A4', string $margins = '12mm'): Response
    {
        if (self::engineAvailable()) {
            try {
                $bytes = $makePdf();

                return response()->streamDownload(fn () => print ($bytes), $filename, ['Content-Type' => 'application/pdf'], 'inline');
            } catch (ProcessFailedException|CouldNotTakeBrowsershot $e) {
                Log::warning('PDF engine failed; serving a print-ready page instead.', ['file' => $filename, 'error' => $e->getMessage()]);
            }
        }

        return response(self::printable($html, pathinfo($filename, PATHINFO_FILENAME), $pageSize, $margins))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * The PDF driver to use (read by config/laravel-pdf.php). Browsershot needs Node + Chrome on the server; plain
     * shared hosting has neither, so there the app switches to dompdf (pure PHP) by itself, even when .env still
     * says "browsershot". Any other driver (dompdf, cloudflare, gotenberg, ...) is used as configured.
     */
    public static function resolveDriver(?string $configured, ?string $nodeBinary): string
    {
        $driver = $configured ?: 'browsershot';
        if ($driver !== 'browsershot') {
            return $driver;
        }
        $node = $nodeBinary ?: (new ExecutableFinder)->find('node');

        return $node && is_file($node) ? 'browsershot' : 'dompdf';
    }

    /**
     * A PDF builder for $html with an optional page footer. Chrome-style footers (spans "pageNumber" / "totalPages")
     * become a footer fixed to every page with dompdf's own page counters when dompdf renders.
     */
    public static function builder(string $html, ?string $footer = null): PdfBuilder
    {
        if ($footer === null) {
            return Pdf::html($html);
        }
        if (config('laravel-pdf.driver') !== 'dompdf') {
            return Pdf::html($html)->footerHtml($footer);
        }

        // dompdf counts the current page in CSS but not the total, so "Page 2 of 3" becomes "Page 2".
        $footer = (string) preg_replace('/\s*of\s*<span class="totalPages"><\/span>/i', '', $footer);
        $footer = str_replace('<span class="pageNumber"></span>', '<span class="dp-page"></span>', $footer);
        $css = '<style>.dp-footer { position: fixed; left: 0; right: 0; bottom: -10mm; height: 8mm; }'
            .' .dp-page:after { content: counter(page); }</style>';
        $html = preg_match('/<\/head>/i', $html) ? preg_replace('/<\/head>/i', $css.'</head>', $html, 1) : $css.$html;
        $block = '<div class="dp-footer">'.$footer.'</div>';
        $html = preg_match('/<body[^>]*>/i', $html) ? preg_replace('/(<body[^>]*>)/i', '$1'.str_replace('$', '\$', $block), $html, 1) : $block.$html;

        return Pdf::html($html);
    }

    /** Can this server make a PDF right now? Remote drivers (Cloudflare, Gotenberg, ...) need nothing installed here. */
    public static function engineAvailable(): bool
    {
        if (config('laravel-pdf.driver', 'browsershot') !== 'browsershot') {
            return true;
        }

        $node = config('laravel-pdf.browsershot.node_binary') ?: (new ExecutableFinder)->find('node');
        if (! $node || ! is_file($node)) {
            return false;
        }
        $chrome = config('laravel-pdf.browsershot.chrome_path');

        return ! $chrome || is_file($chrome);
    }

    /** The document plus page size, a "Print / Save as PDF" bar (screen only) and the print window on load. */
    public static function printable(string $html, string $title, string $pageSize = 'A4', string $margins = '12mm'): string
    {
        $css = '<style>@page { size: '.$pageSize.'; margin: '.$margins.'; }'
            .' html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }'
            .' .pdf-print-bar { position: sticky; top: 0; z-index: 50; display: flex; gap: 12px; align-items: center; justify-content: space-between;'
            .' padding: 10px 16px; background: #0B3466; color: #fff; font: 14px system-ui, sans-serif; }'
            .' .pdf-print-bar button { background: #13A35C; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 600; cursor: pointer; }'
            .' @media print { .pdf-print-bar { display: none; } }</style>';
        $bar = '<div class="pdf-print-bar"><span><b>'.e($title).'</b> &middot; In the print window choose <b>Save as PDF</b> to keep a file.'
            .' Turn on <b>Background graphics</b> for the colours.</span><button type="button" onclick="window.print()">Print / Save as PDF</button></div>';
        $script = '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 300); });</script>';

        $html = preg_match('/<\/head>/i', $html) ? preg_replace('/<\/head>/i', $css.'</head>', $html, 1) : $css.$html;
        $html = preg_match('/<body[^>]*>/i', $html) ? preg_replace('/(<body[^>]*>)/i', '$1'.$bar, $html, 1) : $bar.$html;

        return preg_match('/<\/body>/i', $html) ? preg_replace('/<\/body>/i', $script.'</body>', $html, 1) : $html.$script;
    }
}
