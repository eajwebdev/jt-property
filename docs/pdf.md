# Statement PDFs

Statements are server-rendered from `resources/views/pdf/statement.blade.php` (A4, Instrument Sans embedded,
`Page X of Y` footer) with `spatie/laravel-pdf` using the Browsershot driver and a local Chrome.

Setup
- `composer install` and `npm install` (the `puppeteer` dev dependency is an alias of `puppeteer-core`, so no Chromium download).
- Chrome path: env `PDF_CHROME_PATH`; on Windows it defaults to `C:\Program Files\Google\Chrome\Application\chrome.exe` when that exists.
  On Linux set it to e.g. `/usr/bin/chromium` and, in containers, `LARAVEL_PDF_NO_SANDBOX=true`. Node must be on PATH.
- Issued PDFs live on the private `local` disk at `statements/{year}/{number}.pdf`; the first render writes a `statement_snapshots` row
  (path, sha256, totals). Later downloads and `statement-copy.pdf` use the snapshot, never the live ledger.

Fallback if Chrome/Node cannot be used (not installed here)
1. `composer require dompdf/dompdf` and set `LARAVEL_PDF_DRIVER=dompdf`.
2. dompdf has weaker CSS: replace `clip-path`, CSS grid and flex in `statement.blade.php` with tables, and use `DejaVu Sans` for the peso sign.
   Header/footer HTML is not supported, so print `Page X of Y` with a dompdf page script instead.
