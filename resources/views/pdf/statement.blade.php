@php
    use App\Support\Money;
    use App\Support\Quantity;

    $d = fn ($date) => \Illuminate\Support\Carbon::parse($date)->format('d M Y');
    $peso = fn (int $c) => Money::format($c);
    $b64 = fn (string $path) => base64_encode((string) @file_get_contents($path));
    $font = fn (int $w) => 'data:font/woff2;base64,'.$b64(resource_path("fonts/instrument-sans-latin-{$w}.woff2"));
    $logo = 'data:image/png;base64,'.$b64(resource_path('img/jt-logo-horizontal-pdf.png'));
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
<style>
    @font-face { font-family: 'Instrument Sans'; font-weight: 400; src: url({{ $font(400) }}) format('woff2'); }
    @font-face { font-family: 'Instrument Sans'; font-weight: 600; src: url({{ $font(600) }}) format('woff2'); }
    @font-face { font-family: 'Instrument Sans'; font-weight: 700; src: url({{ $font(700) }}) format('woff2'); }
    :root { --navy:#064578; --teal:#007A69; --mint:#24C5A4; --mint-soft:#E6F7F3; --canvas:#F5F9FC; --text:#0F1E2E; --muted:#52637A; --border:#DCE5EE; --danger:#C2362F; }
    * { box-sizing: border-box; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; color: var(--text); background: #fff; font: 400 9pt/1.35 'Instrument Sans', 'Segoe UI', 'Noto Sans', 'DejaVu Sans', Arial, sans-serif; }
    .sheet { width: 210mm; min-height: 266mm; margin: 0 auto; padding: 0 12mm; display: flex; flex-direction: column; position: relative; }
    .num, td.amt, .amt { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
    .muted { color: var(--muted); }
    .label { font-size: 7.5pt; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
    h2 { margin: 0; font-size: 10.5pt; }

    .head { display: flex; align-items: stretch; justify-content: space-between; height: 18mm; margin: 0 -12mm 3mm; }
    .head .logo { display: flex; align-items: center; padding-left: 12mm; }
    .head .logo img { height: 14mm; }
    .head .title { background: var(--navy); color: #fff; width: 112mm; padding: 3.2mm 12mm 0 14mm; clip-path: polygon(8mm 0, 100% 0, 100% 100%, 0 100%); border-bottom: 1.2mm solid var(--mint); }
    .head .title strong { display: block; font-size: 12.5pt; white-space: nowrap; letter-spacing: .03em; line-height: 1.15; }
    .head .title span { font-size: 8.5pt; opacity: .9; }

    .meta { display: grid; grid-template-columns: 1fr 1.25fr 1.05fr 0.9fr; background: var(--canvas); overflow: hidden; border: 1px solid var(--border); border-radius: 2mm; margin-bottom: 3mm; break-inside: avoid; }
    .meta div { padding: 1.4mm 4mm; }
    .meta div + div { border-left: 1px solid var(--border); } .meta .dueb { background: var(--mint-soft); } .meta .dueb b { color: var(--navy); font-size: 12pt; }
    .meta b { display: block; font-weight: 600; font-size: 10pt; overflow-wrap: anywhere; }

    .top { display: grid; grid-template-columns: 1.3fr 1fr; gap: 4mm; margin-bottom: 3mm; break-inside: avoid; }
    .card { border: 1px solid var(--border); border-radius: 2mm; overflow: hidden; background: #fff; }
    .card + .card { margin-top: 3mm; }
    .card h2 { padding: 1.6mm 4mm; background: var(--navy); color: #fff; }
    .card h2.teal { background: var(--teal); }
    .ident { padding: 2.4mm 4mm; display: grid; grid-template-columns: 1fr 1fr; gap: 2.5mm 4mm; border: 1px solid var(--border); border-radius: 2mm; background: var(--canvas); }
    .ident > div { min-width: 0; }
    .ident b { display: block; font-weight: 600; overflow-wrap: anywhere; }
    .ident .wide { grid-column: 1 / -1; }
    .due { background: var(--mint-soft); border: 1.5px solid var(--mint); border-radius: 2mm; padding: 2.5mm 4mm; }
    .due b { display: block; font-size: 17pt; color: var(--navy); line-height: 1.15; }
    .sum { width: 100%; border-collapse: collapse; }
    .sum td { padding: 1.5mm 4mm; border-bottom: 1px solid var(--border); }
    .sum tr.total td { background: var(--teal); color: #fff; font-weight: 700; font-size: 11pt; border: 0; padding: 2.4mm 4mm; }
    .sum tr.total.credit td { background: var(--navy); }
    .sum tr.past td { color: var(--danger); font-weight: 600; border: 0; }
    .contact { padding: 2.5mm 4mm; font-size: 8.5pt; overflow-wrap: anywhere; }
    .contact div { margin-bottom: 1mm; }

    table.lines { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
    table.lines caption { text-align: left; padding: 1.8mm 4mm; background: var(--navy); color: #fff; font-weight: 600; font-size: 10.5pt; border-radius: 2mm 2mm 0 0; caption-side: top; }
    table.lines thead { display: table-header-group; }
    table.lines th { background: #E8EFF6; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .04em; padding: 1.5mm 3mm; border-bottom: 1px solid var(--border); }
    table.lines th.amt { text-align: right; }
    table.lines td { padding: 1.2mm 3mm; border-bottom: 1px solid var(--border); vertical-align: top; overflow-wrap: anywhere; }
    table.lines tr { break-inside: avoid; }
    table.lines td.no { width: 9mm; color: var(--muted); }
    table.lines td.per { width: 52mm; font-size: 8.5pt; color: var(--muted); }
    table.lines td.amt { width: 30mm; }
    table.lines tfoot td { font-weight: 700; background: var(--canvas); }
    .sub { display: block; font-size: 8pt; color: var(--muted); }

    .bottom { display: grid; grid-template-columns: 1fr; gap: 3mm; margin-bottom: 4mm; }
    .pair { display: grid; grid-template-columns: 1.7fr 1fr; gap: 3mm; align-items: start; }
    .pair > .card:only-child { grid-column: 1 / -1; }
    .pay { display: grid; grid-template-columns: repeat(auto-fit, minmax(38mm, 1fr)); }
    .pay > div { padding: 2.5mm 4mm; font-size: 8.5pt; overflow-wrap: anywhere; }
    .pay > div + div { border-left: 1px solid var(--border); }
    .pay b { display: block; color: var(--navy); font-size: 9.5pt; }
    .note { background: var(--mint-soft); border-radius: 2mm; padding: 2.2mm 4mm; font-size: 8.8pt; break-inside: avoid; white-space: pre-line; }

    .stub { margin-top: auto; border-top: .4mm dashed #7a8aa0; padding-top: 3mm; break-inside: avoid; }
    .stub .cut { font-size: 7pt; color: var(--muted); text-align: center; margin: -5.2mm auto 2mm; width: 52mm; white-space: nowrap; background: #fff; }
    .stub .row { display: grid; grid-template-columns: 1fr 62mm; gap: 5mm; align-items: stretch; }
    .stub .who { display: flex; align-items: center; gap: 4mm; margin-bottom: 1mm; }
    .stub .who img { height: 9mm; }
    .stub table { width: 100%; border-collapse: collapse; font-size: 8.8pt; }
    .stub th { text-align: left; font-weight: 600; width: 30mm; padding: .6mm 2mm; background: var(--canvas); border-bottom: 1px solid var(--border); }
    .stub td { padding: .6mm 2mm; border-bottom: 1px solid var(--border); overflow-wrap: anywhere; }
    .stub .amount { border: 1.5px solid var(--mint); background: var(--mint-soft); border-radius: 2mm; text-align: center; padding: 2mm; display: flex; flex-direction: column; justify-content: center; }
    .stub .amount b { font-size: 18pt; color: var(--navy); }
    .stub .amount.credit b { color: var(--teal); }
    .fine { font-size: 7.5pt; color: var(--muted); margin-top: 1.5mm; }

    .watermark { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 150pt; font-weight: 700; color: rgba(194, 54, 47, .16); transform: rotate(-28deg); letter-spacing: .08em; pointer-events: none; z-index: 5; }
    .void-note { background: #FBEAE9; color: var(--danger); border: 1px solid var(--danger); border-radius: 2mm; padding: 2mm 4mm; margin-bottom: 4mm; font-weight: 600; break-inside: avoid; }

    .toolbar { position: sticky; top: 0; z-index: 10; background: var(--navy); color: #fff; padding: 2.5mm 6mm; display: flex; justify-content: space-between; align-items: center; font-size: 10pt; }
    .toolbar a { color: #fff; background: var(--teal); padding: 1.6mm 4mm; border-radius: 1.5mm; text-decoration: none; font-weight: 600; }
    @media print { .toolbar { display: none; } }
    @media screen { body { background: #e9eef4; } .sheet { background: #fff; margin-top: 6mm; margin-bottom: 6mm; box-shadow: 0 1px 8px rgba(6, 69, 120, .2); } }
    @media screen and (max-width: 215mm) { .sheet { width: 100%; padding: 0 5mm; } .head { margin: 0 -5mm 4mm; } .head .title { width: 60%; padding-right: 5mm; } }
    @page { size: A4; }
</style>
</head>
<body>
@if ($preview)
    <div class="toolbar">
        <span>Preview – this is not sent to anyone</span>
        <a href="{{ route('invoices.statement.pdf', $s->invoiceId) }}">Download PDF</a>
    </div>
@endif
@if ($s->isVoid)
    <div class="watermark">VOID</div>
@endif
<div class="sheet">
    <div class="head">
        <div class="logo"><img src="{{ $logo }}" alt="{{ $co['company_name'] }}"></div>
        <div class="title">
            <strong>MONTHLY BILLING STATEMENT</strong>
            <span>Rental Property Account Statement</span>
        </div>
    </div>

    @if ($s->isVoid)
        <div class="void-note">VOID – this bill was cancelled{{ $s->voidReason ? ': '.$s->voidReason : '' }}. Do not pay.</div>
    @endif

    <div class="meta">
        <div><span class="label">Statement date</span><b>{{ $d($s->issueDate) }}</b></div>
        <div><span class="label">Billing period</span><b>{{ $period }}</b></div>
        <div><span class="label">Invoice number</span><b>{{ $s->number }}</b></div>
        <div class="dueb"><span class="label">Due date</span><b>{{ $d($s->dueDate) }}</b></div>
    </div>

    <div class="top">
        <div>
            <div class="ident">
                <div class="wide"><span class="label">Tenant</span><b>{{ $s->tenantName }}</b></div>
                <div><span class="label">Property</span><b>{{ $s->propertyName }}</b></div>
                <div><span class="label">Unit / Room</span><b>{{ $s->unitLabel }}</b></div>
                @if ($s->propertyAddress)
                    <div class="wide"><span class="label">Address</span><b>{{ $s->propertyAddress }}</b></div>
                @endif
                <div class="wide"><span class="label">Account number</span><b>{{ $s->accountNo }}</b></div>
            </div>
        </div>
        <div>
            <div class="card">
                <h2>Billing Summary</h2>
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
        </div>
    </div>

    <table class="lines">
        <caption>Billing Details</caption>
        <thead>
            <tr><th>No.</th><th>Description</th><th>Period / Usage</th><th class="amt">Amount (₱)</th></tr>
        </thead>
        <tbody>
            @foreach ($s->lines as $i => $l)
                <tr>
                    <td class="no">{{ $i + 1 }}</td>
                    <td>{{ $l['label'] }}@if ($l['description'])<span class="sub">{{ $l['description'] }}</span>@endif</td>
                    <td class="per">{{ $usage($l) }}</td>
                    <td class="amt">{{ $peso($l['amount_cents']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td></td><td colspan="2">Current charges</td><td class="amt">{{ $peso($s->currentCents) }}</td></tr>
        </tfoot>
    </table>

    @if ($s->payments)
        <table class="lines">
            <caption>Payments &amp; Credits Since {{ $d($s->periodStart) }}</caption>
            <thead><tr><th>Date</th><th colspan="2">Details</th><th class="amt">Amount (₱)</th></tr></thead>
            <tbody>
                @foreach ($s->payments as $p)
                    <tr>
                        <td class="per" style="width:28mm">{{ $d($p['date']) }}</td>
                        <td colspan="2">{{ $typeLabel[$p['type']] ?? ucfirst($p['type']) }}@if ($p['memo']) – {{ $p['memo'] }}@endif</td>
                        <td class="amt">{{ $peso($p['amount_cents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($s->openingBreakdown && $s->previousCents !== 0)
        <div class="note" style="margin-bottom:4mm">Previous balance includes an opening balance brought forward from the earlier record.</div>
    @endif

    <div class="bottom">
        <div class="pair">
        @if ($co['payment_instructions'])
            <div class="card" style="break-inside:avoid; margin:0">
                <h2 class="teal">Payment Instructions</h2>
                <div class="pay">
                    @foreach ($co['payment_instructions'] as $ins)
                        <div>
                            <b>{{ $ins['label'] }}</b>
                            {{ $ins['account_name'] }}<br>
                            {{ $ins['account_number'] }}
                            @if (! empty($ins['notes']))<br><span class="muted">{{ $ins['notes'] }}</span>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if ($co['company_phone'] || $co['company_email'] || $co['company_address'])
            <div class="card" style="break-inside:avoid; margin:0">
                <h2>Contact Us</h2>
                <div class="contact">
                    <div style="font-weight:600">{{ $co['company_name'] }}</div>
                    @if ($co['company_phone'])<div>{{ $co['company_phone'] }}</div>@endif
                    @if ($co['company_email'])<div>{{ $co['company_email'] }}</div>@endif
                    @if ($co['company_address'])<div>{{ $co['company_address'] }}</div>@endif
                </div>
            </div>
        @endif
        </div>
        <div class="note"><b>Important:</b> Please pay on or before the due date ({{ $d($s->dueDate) }}).@if ($co['statement_notes']) {{ $co['statement_notes'] }}@endif</div>
    </div>

    <div class="stub">
        <div class="cut">Detach and send with payment</div>
        <div class="row">
            <div>
                <div class="who"><img src="{{ $logo }}" alt=""><b style="font-size:11pt;color:var(--navy)">Payment Slip</b></div>
                <table>
                    <tr><th>Account number</th><td>{{ $s->accountNo }}</td></tr>
                    <tr><th>Invoice number</th><td>{{ $s->number }}</td></tr>
                    <tr><th>Tenant</th><td>{{ $s->tenantName }}</td></tr>
                    <tr><th>Property / Unit</th><td>{{ $s->propertyName }} – {{ $s->unitLabel }}</td></tr>
                    <tr><th>Billing period</th><td>{{ $period }}</td></tr>
                    <tr><th>Due date</th><td>{{ $d($s->dueDate) }}</td></tr>
                </table>
            </div>
            <div class="amount {{ $s->isCredit ? 'credit' : '' }}">
                <span class="label" style="color:var(--text)">{{ $s->isCredit ? 'Credit balance' : 'Total amount due' }}</span>
                <b>{{ $peso($s->totalDueCents) }}</b>
            </div>
        </div>
        <div class="fine">This is a billing statement, not an official receipt.</div>
    </div>
</div>
</body>
</html>
