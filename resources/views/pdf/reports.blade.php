@php
    use App\Support\Money;

    $selectedProperty = $filters['property_id'] === null
        ? 'All accessible properties'
        : $properties->firstWhere('id', $filters['property_id'])?->name;
    $agingBuckets = ['current', '1-30', '31-60', '61-90', '90+'];
    $logo = 'data:image/png;base64,'.base64_encode((string) @file_get_contents(resource_path('img/mubarak-logo-horizontal-pdf.png')));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Management reports - {{ $filters['month'] }}</title>
    <style>
        @page { size: A4 landscape; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #142638; font: 10px/1.4 {!! config('laravel-pdf.driver') === 'dompdf' ? "'DejaVu Sans', " : '' !!}Arial, sans-serif; }
        .header { width: 100%; border-collapse: collapse; table-layout: auto; border-bottom: 3px solid #0B7A48; } .header td { border: 0; padding: 0 0 12px; vertical-align: middle; }
        .logo { width: 170px; max-height: 50px; object-fit: contain; object-position: left center; }
        h1 { margin: 15px 0 2px; color: #0B3466; font-size: 23px; }
        h2 { margin: 20px 0 8px; color: #0B3466; font-size: 14px; }
        .muted { color: #52637a; }
        .period { text-align: right; }
        .metrics { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 15px -8px 0; table-layout: fixed; } .metrics td { border: 0; }
        .metric { padding: 10px; background: #eef5f8; border-radius: 5px; vertical-align: top; }
        .metric .value { display: block; margin-top: 4px; font-size: 14px; font-weight: bold; white-space: nowrap; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 6px 5px; border-bottom: 1px solid #dce5ee; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #0B3466; color: white; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        tfoot td { background: #E6F4EC; font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .empty { padding: 10px 0; color: #52637a; }
        .section { break-inside: avoid-page; }
        .note { margin: 6px 0; color: #52637a; }
    </style>
</head>
<body>
    <table class="header"><tr>
        <td><img class="logo" src="{{ $logo }}" alt="Mubarak Boardinghouse"></td>
        <td class="period"><strong>{{ $selectedProperty }}</strong><br>{{ \Illuminate\Support\Carbon::parse($filters['month'].'-01')->format('F Y') }}<br><span class="muted">Generated {{ today()->format('d M Y') }}</span></td>
    </tr></table>
    <h1>Management reports</h1>
    <div class="muted">Billings are accrual charges. Collections are approved cash receipts. Aging is based on unpaid ledger charges.</div>

    <table class="metrics"><tr>
        <td class="metric">Billed in month<span class="value">{{ Money::format($monthly['billed_cents']) }}</span></td>
        <td class="metric">Collected in month<span class="value">{{ Money::format($monthly['collected_cents']) }}</span></td>
        <td class="metric">Current net receivable<span class="value">{{ Money::format($aging['net_receivable_cents']) }}</span></td>
        <td class="metric">Security deposits held<span class="value">{{ Money::format($depositsHeldCents) }}</span></td>
        <td class="metric">Unpaid opening balances<span class="value">{{ Money::format($unpaidOpeningsCents) }}</span></td>
    </tr></table>

    <h2>Property summary</h2>
    <table>
        <thead><tr><th style="width:40%">Property</th><th class="num" style="width:15%">Occupied / capacity</th><th class="num" style="width:15%">Billed</th><th class="num" style="width:15%">Collected</th><th class="num" style="width:15%">Net receivable</th></tr></thead>
        <tbody>@forelse ($propertySummary as $row)
            <tr><td>{{ $row['name'] }}</td><td class="num">{{ $row['occupied'] }} / {{ $row['capacity'] }}</td><td class="num">{{ Money::format($row['billed_cents']) }}</td><td class="num">{{ Money::format($row['collected_cents']) }}</td><td class="num">{{ Money::format($row['net_receivable_cents']) }}</td></tr>
        @empty
            <tr><td colspan="5" class="empty">No properties available.</td></tr>
        @endforelse</tbody>
    </table>

    <div class="section">
        <h2>Billed charge types in month</h2>
        <table><thead><tr><th>Charge type</th><th class="num">Amount</th></tr></thead><tbody>
            @forelse ($chargeCategories as $category => $cents)
                <tr><td>{{ ucwords(str_replace('_', ' ', $category)) }}</td><td class="num">{{ Money::format($cents) }}</td></tr>
            @empty
                <tr><td colspan="2" class="empty">No bills issued in this month.</td></tr>
            @endforelse
        </tbody></table>
    </div>

    <h2>Outstanding charges by days past due</h2>
    <p class="note">Unallocated account credits are reflected in net receivable above.</p>
    <table>
        <thead><tr><th style="width:19%">Account / tenant</th><th style="width:15%">Property</th><th style="width:10%">Oldest due</th>@foreach ($agingBuckets as $bucket)<th class="num" style="width:9%">{{ $bucket }}</th>@endforeach<th class="num" style="width:11%">Open total</th></tr></thead>
        <tbody>@forelse ($aging['rows'] as $row)
            <tr><td>{{ $row['tenant'] }}<br><span class="muted">{{ $row['account_no'] }}</span></td><td>{{ $row['property'] }}</td><td>{{ $row['oldest_due'] ?? '-' }}</td>@foreach ($agingBuckets as $bucket)<td class="num">{{ Money::format($row['buckets'][$bucket]) }}</td>@endforeach<td class="num">{{ Money::format($row['open_cents']) }}</td></tr>
        @empty
            <tr><td colspan="9" class="empty">No outstanding charges or credits for this selection.</td></tr>
        @endforelse</tbody>
        <tfoot><tr><td colspan="3">Total open charges</td>@foreach ($agingBuckets as $bucket)<td class="num">{{ Money::format($aging['totals'][$bucket]) }}</td>@endforeach<td class="num">{{ Money::format(array_sum($aging['totals'])) }}</td></tr></tfoot>
    </table>

    <div class="section">
        <h2>Rent roll at month end</h2>
        <p class="note">Contracted base rent for active agreements; invoices may include prorations and other charges.</p>
        <table>
            <thead><tr><th style="width:30%">Tenant / account</th><th style="width:30%">Property</th><th style="width:15%">Room</th><th class="num" style="width:10%">Due day</th><th class="num" style="width:15%">Base rent</th></tr></thead>
            <tbody>@forelse ($rentRoll as $row)
                <tr><td>{{ $row['tenant'] }}<br><span class="muted">{{ $row['account_no'] }}</span></td><td>{{ $row['property'] }}</td><td>{{ $row['unit'] }}</td><td class="num">{{ $row['due_day'] }}</td><td class="num">{{ Money::format($row['rent_cents']) }}</td></tr>
            @empty
                <tr><td colspan="5" class="empty">No active agreements at month end.</td></tr>
            @endforelse</tbody>
        </table>
    </div>
</body>
</html>
