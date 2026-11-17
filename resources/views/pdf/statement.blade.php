@php
    use App\Support\Money;
    use App\Support\Quantity;

    $d = fn ($date) => \Illuminate\Support\Carbon::parse($date)->format('d M Y');
    $peso = fn (int $c) => Money::format($c);
    $b64 = fn (string $path) => base64_encode((string) @file_get_contents($path));
    // dompdf (plain PHP hosting) cannot read woff2 web fonts: it uses its built-in DejaVu Sans, which has the ₱ sign.
    $webFonts = config('laravel-pdf.driver') !== 'dompdf';
    $font = fn (int $w) => 'data:font/woff2;base64,'.$b64(resource_path("fonts/instrument-sans-latin-{$w}.woff2"));
    $logo = 'data:image/png;base64,'.$b64(resource_path('img/mubarak-logo-horizontal-pdf.png'));
    $period = $d($s->periodStart).' – '.$d($s->periodEnd);
    $usage = function (array $l) use ($period) {
        if ($l['qty_e3'] !== null && $l['unit_rate_e4'] !== null) {
            return Quantity::formatE3($l['qty_e3']).' × ₱'.Quantity::formatRateE4($l['unit_rate_e4']);
        }

        return $period;
    };
    $typeLabel = ['payment' => 'Payment', 'credit' => 'Credit', 'adjustment' => 'Adjustment', 'refund' => 'Refund', 'writeoff' => 'Write-off', 'reversal' => 'Reversal'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Statement {{ $s->number }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
{{--
    Layout uses tables only (no flex/grid/CSS variables) so it renders the same in Chrome, in dompdf on plain
    PHP hosting, and when the browser prints it. Colours: navy #0B3466, green #0B7A48, mint #13A35C.
--}}
<style>
    @if ($webFonts)
    @font-face { font-family: 'Instrument Sans'; font-weight: 400; src: url({{ $font(400) }}) format('woff2'); }
    @font-face { font-family: 'Instrument Sans'; font-weight: 600; src: url({{ $font(600) }}) format('woff2'); }
    @font-face { font-family: 'Instrument Sans'; font-weight: 700; src: url({{ $font(700) }}) format('woff2'); }
    @endif
    @page { size: A4; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; color: #0F1E2E; background: #fff; font-size: 8.6pt; line-height: 1.3;
        font-family: {!! $webFonts ? "'Instrument Sans', 'Segoe UI', 'Noto Sans', 'DejaVu Sans', Arial, sans-serif" : "'DejaVu Sans', sans-serif" !!}; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    /* 186mm content + 2 x 12mm padding = the 210mm of A4. A fixed width also keeps dompdf's table layout fast. */
    .sheet { width: 186mm; margin: 0 auto; padding: 0 12mm; position: relative; }
    .amt { text-align: right; white-space: nowrap; }
    .muted { color: #52637A; }
    .label { display: block; font-size: 7pt; color: #52637A; text-transform: uppercase; letter-spacing: .04em; }
    .val { display: block; font-weight: bold; font-size: 9pt; }

    .head td { padding: 0; vertical-align: middle; }
    .head .logo img { height: 13mm; }
    .head .title { background: #0B3466; color: #fff; width: 104mm; padding: 3mm 6mm 2.4mm 8mm; border-bottom: 1.2mm solid #13A35C; }
    .head .title b { display: block; font-size: 12pt; letter-spacing: .03em; }
    .head .title span { font-size: 8pt; }

    .box { border: 1px solid #DCE5EE; }
    .meta { margin: 3mm 0; background: #F5F9FC; }
    .meta td { padding: 1.4mm 3mm; border-left: 1px solid #DCE5EE; }
    .meta td:first-child { border-left: 0; }
    .meta td.dueb { background: #E6F4EC; }
    .meta td.dueb .val { color: #0B3466; font-size: 11pt; }

    .split > tbody > tr > td { padding: 0; }
    .ident { background: #F5F9FC; }
    .ident td { padding: 1.3mm 3mm; }
    .card-h { background: #0B3466; color: #fff; font-weight: bold; font-size: 10pt; padding: 1.5mm 3mm; }
    .card-h.green { background: #0B7A48; }
    .sum td { padding: 1.3mm 3mm; border-bottom: 1px solid #DCE5EE; }
    .sum tr.total td { background: #0B7A48; color: #fff; font-weight: bold; font-size: 10.5pt; padding: 2.2mm 3mm; border: 0; }
    .sum tr.total.credit td { background: #0B3466; }
    .sum tr.past td { color: #C2362F; font-weight: bold; border: 0; }

    .lines { margin-top: 3mm; }
    .lines th { background: #E8EFF6; text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .04em; padding: 1.4mm 2.5mm; border-bottom: 1px solid #DCE5EE; }
    .lines td { padding: 1.1mm 2.5mm; border-bottom: 1px solid #DCE5EE; }
    .lines tr { page-break-inside: avoid; }
    .lines td.no { width: 8mm; color: #52637A; }
    .lines td.per { width: 48mm; font-size: 8pt; color: #52637A; }
    .lines td.amt { width: 28mm; }
    .lines tr.foot td { font-weight: bold; background: #F5F9FC; }
    .sub { display: block; font-size: 7.5pt; color: #52637A; }

    .pay td { padding: 2mm 3mm; font-size: 8.2pt; border-left: 1px solid #DCE5EE; }
    .pay td:first-child { border-left: 0; }
    .pay b { display: block; color: #0B3466; }
    .contact { padding: 2mm 3mm; font-size: 8.2pt; }
    .note { background: #E6F4EC; padding: 2mm 3mm; font-size: 8.4pt; margin-top: 3mm; }

    .stub { margin-top: 6mm; border-top: .4mm dashed #7a8aa0; padding-top: 2mm; page-break-inside: avoid; }
    .stub .cut { font-size: 7pt; color: #52637A; text-align: center; margin-bottom: 2mm; }
    .stub .slip td, .stub .slip th { padding: .5mm 2mm; border-bottom: 1px solid #DCE5EE; font-size: 8.4pt; text-align: left; }
    .stub .slip th { width: 30mm; background: #F5F9FC; }
    .stub .who img { height: 8mm; vertical-align: middle; }
    .stub .amount { border: 1.5px solid #13A35C; background: #E6F4EC; text-align: center; vertical-align: middle; padding: 3mm 2mm; }
    .stub .amount b { display: block; font-size: 17pt; color: #0B3466; }
    .stub .amount.credit b { color: #0B7A48; }
    .fine { font-size: 7pt; color: #52637A; margin-top: 1.5mm; }

    .watermark { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 130pt; font-weight: bold; color: #C2362F; opacity: .16; transform: rotate(-28deg); }
    .void-note { background: #FBEAE9; color: #C2362F; border: 1px solid #C2362F; padding: 2mm 3mm; margin-top: 2mm; font-weight: bold; }

    .toolbar { position: sticky; top: 0; z-index: 10; background: #0B3466; color: #fff; padding: 2.5mm 6mm; font-size: 10pt; }
    .toolbar a, .toolbar button { color: #fff; background: #0B7A48; padding: 1.6mm 4mm; border: 0; border-radius: 1.5mm; text-decoration: none; font: inherit; font-weight: bold; cursor: pointer; float: right; margin-left: 2mm; }
    @media print { .toolbar { display: none; } }
    @if ($preview)
    {{-- Browser preview only: the A4 sheet on a grey desk. Never in a PDF (Chrome and dompdf both render as "screen"). --}}
    @media screen { body { background: #e9eef4; } .sheet { background: #fff; margin-top: 6mm; margin-bottom: 6mm; padding-bottom: 6mm; box-shadow: 0 1px 8px rgba(11, 52, 102, .2); } }
    @media screen and (max-width: 215mm) { .sheet { width: auto; padding: 0 4mm 6mm; } }
    @endif
</style>
</head>
<body>
@if ($preview)
    <div class="toolbar">
        <a href="{{ route('invoices.statement.pdf', $s->invoiceId) }}">Download PDF</a>
        <button type="button" onclick="window.print()">Print</button>
        <span>Preview – this is not sent to anyone</span>
    </div>
@endif
@if ($s->isVoid)
    <div class="watermark">VOID</div>
@endif
<div class="sheet">
    <table class="head">
        <tr>
            <td class="logo"><img src="{{ $logo }}" alt="{{ $co['company_name'] }}"></td>
            <td class="title"><b>MONTHLY BILLING STATEMENT</b><span>Rental Property Account Statement</span></td>
        </tr>
    </table>

    @if ($s->isVoid)
        <div class="void-note">VOID – this bill was cancelled{{ $s->voidReason ? ': '.$s->voidReason : '' }}. Do not pay.</div>
    @endif

    <table class="meta box">
        <tr>
            <td style="width:22%"><span class="label">Statement date</span><span class="val">{{ $d($s->issueDate) }}</span></td>
            <td style="width:32%"><span class="label">Billing period</span><span class="val">{{ $period }}</span></td>
            <td style="width:26%"><span class="label">Invoice number</span><span class="val">{{ $s->number }}</span></td>
            <td class="dueb"><span class="label">Due date</span><span class="val">{{ $d($s->dueDate) }}</span></td>
        </tr>
    </table>

    <table class="split">
        <tr>
            <td style="width:56%; padding-right:3mm">
                <table class="ident box">
                    <tr><td colspan="2"><span class="label">Tenant</span><span class="val">{{ $s->tenantName }}</span></td></tr>
                    <tr>
                        <td style="width:50%"><span class="label">Property</span><span class="val">{{ $s->propertyName }}</span></td>
                        <td><span class="label">Unit / Room</span><span class="val">{{ $s->unitLabel }}</span></td>
                    </tr>
                    @if ($s->propertyAddress)
                        <tr><td colspan="2"><span class="label">Address</span><span class="val">{{ $s->propertyAddress }}</span></td></tr>
                    @endif
                    <tr><td colspan="2"><span class="label">Account number</span><span class="val">{{ $s->accountNo }}</span></td></tr>
                </table>
            </td>
            <td>
                <div class="box">
                    <div class="card-h">Billing Summary</div>
                    <table class="sum">
                        <tr><td>Previous balance</td><td class="amt">{{ $peso($s->previousCents) }}</td></tr>
                        <tr><td>Payments &amp; credits received</td><td class="amt">{{ $s->paymentsSinceCents === 0 ? $peso(0) : $peso($s->paymentsSinceCents) }}</td></tr>
                        <tr><td>Current charges</td><td class="amt">{{ $peso($s->currentCents) }}</td></tr>
                        <tr class="total {{ $s->isCredit ? 'credit' : '' }}"><td>{{ $s->isCredit ? 'Credit balance' : 'Total amount due' }}</td><td class="amt">{{ $peso($s->totalDueCents) }}</td></tr>
                        @if ($s->pastDueCents > 0)
                            <tr class="past"><td>Includes past due</td><td class="amt">{{ $peso($s->pastDueCents) }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="lines box">
        <div class="card-h">Billing Details</div>
        <table>
            <tr><th>No.</th><th>Description</th><th>Period / Usage</th><th class="amt">Amount (₱)</th></tr>
            @foreach ($s->lines as $i => $l)
                <tr>
                    <td class="no">{{ $i + 1 }}</td>
                    <td>{{ $l['label'] }}@if ($l['description'])<span class="sub">{{ $l['description'] }}</span>@endif</td>
                    <td class="per">{{ $usage($l) }}</td>
                    <td class="amt">{{ $peso($l['amount_cents']) }}</td>
                </tr>
            @endforeach
            <tr class="foot"><td></td><td colspan="2">Current charges</td><td class="amt">{{ $peso($s->currentCents) }}</td></tr>
        </table>
    </div>

    @if ($s->payments)
        <div class="lines box">
            <div class="card-h">Payments &amp; Credits Since {{ $d($s->periodStart) }}</div>
            <table>
                <tr><th>Date</th><th colspan="2">Details</th><th class="amt">Amount (₱)</th></tr>
                @foreach ($s->payments as $p)
                    <tr>
                        <td class="per" style="width:28mm">{{ $d($p['date']) }}</td>
                        <td colspan="2">{{ $typeLabel[$p['type']] ?? ucfirst($p['type']) }}@if ($p['memo']) – {{ $p['memo'] }}@endif</td>
                        <td class="amt">{{ $peso($p['amount_cents']) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($s->openingBreakdown && $s->previousCents !== 0)
        <div class="note">Previous balance includes an opening balance brought forward from the earlier record.</div>
    @endif

    @php($hasContact = $co['company_phone'] || $co['company_email'] || $co['company_address'])
    @if ($co['payment_instructions'] || $hasContact)
        <table class="split" style="margin-top:3mm">
            <tr>
                @if ($co['payment_instructions'])
                    <td style="{{ $hasContact ? 'width:62%; padding-right:3mm' : '' }}">
                        <div class="box">
                            <div class="card-h green">Payment Instructions</div>
                            <table class="pay">
                                <tr>
                                    @foreach ($co['payment_instructions'] as $ins)
                                        <td>
                                            <b>{{ $ins['label'] }}</b>
                                            {{ $ins['account_name'] }}<br>
                                            {{ $ins['account_number'] }}
                                            @if (! empty($ins['notes']))<br><span class="muted">{{ $ins['notes'] }}</span>@endif
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        </div>
                    </td>
                @endif
                @if ($hasContact)
                    <td>
                        <div class="box">
                            <div class="card-h">Contact Us</div>
                            <div class="contact">
                                <b>{{ $co['company_name'] }}</b><br>
                                @if ($co['company_phone']){{ $co['company_phone'] }}<br>@endif
                                @if ($co['company_email']){{ $co['company_email'] }}<br>@endif
                                @if ($co['company_address']){{ $co['company_address'] }}@endif
                            </div>
                        </div>
                    </td>
                @endif
            </tr>
        </table>
    @endif

    <div class="note"><b>Important:</b> Please pay on or before the due date ({{ $d($s->dueDate) }}).@if ($co['statement_notes']) {{ $co['statement_notes'] }}@endif</div>

    <div class="stub">
        <div class="cut">- - - Detach and send with payment - - -</div>
        <table>
            <tr>
                <td style="padding-right:5mm">
                    <div class="who" style="margin-bottom:1.5mm"><img src="{{ $logo }}" alt=""> <b style="font-size:10.5pt;color:#0B3466">&nbsp;Payment Slip</b></div>
                    <table class="slip">
                        <tr><th>Account number</th><td>{{ $s->accountNo }}</td></tr>
                        <tr><th>Invoice number</th><td>{{ $s->number }}</td></tr>
                        <tr><th>Tenant</th><td>{{ $s->tenantName }}</td></tr>
                        <tr><th>Property / Unit</th><td>{{ $s->propertyName }} – {{ $s->unitLabel }}</td></tr>
                        <tr><th>Billing period</th><td>{{ $period }}</td></tr>
                        <tr><th>Due date</th><td>{{ $d($s->dueDate) }}</td></tr>
                    </table>
                </td>
                <td class="amount {{ $s->isCredit ? 'credit' : '' }}" style="width:58mm">
                    <span class="label" style="color:#0F1E2E">{{ $s->isCredit ? 'Credit balance' : 'Total amount due' }}</span>
                    <b>{{ $peso($s->totalDueCents) }}</b>
                </td>
            </tr>
        </table>
        <div class="fine">This is a billing statement, not an official receipt.</div>
    </div>
</div>
</body>
</html>
