@php
    use App\Support\Money;
    use App\Support\StatementSettings;

    $co = StatementSettings::get();
    $logo = 'data:image/png;base64,'.base64_encode((string) @file_get_contents(resource_path('img/mubarak-logo-horizontal-pdf.png')));
    $peso = fn (int $c) => Money::format($c);
    $kinds = ['charge' => 'Bill', 'opening' => 'Balance brought forward', 'payment' => 'Payment', 'credit' => 'Credit', 'adjustment' => 'Adjustment', 'refund' => 'Refund', 'reversal' => 'Reversal', 'writeoff' => 'Write-off'];
    $details = function (array $row) {
        $memo = (string) ($row['memo'] ?? '');
        if ($row['type'] === 'payment') {
            $memo = ucfirst(str_replace('_', ' ', preg_replace('/^Payment\s+/i', '', $memo)));
        }

        return preg_replace('/^Invoice\s+/i', '', $memo);
    };
    $charges = collect($statement->rows)->where('amount_cents', '>', 0)->sum('amount_cents');
    $paid = -collect($statement->rows)->where('amount_cents', '<', 0)->sum('amount_cents');
    $today = today()->toDateString();
    $current = $account->agreements->filter(fn ($a) => $a->end_date === null || $a->end_date->toDateString() >= $today);
    $rooms = ($current->isNotEmpty() ? $current : $account->agreements->sortByDesc('start_date')->take(1))->map(fn ($a) => $a->unit->label)->unique()->join(', ');
    $closing = $statement->closingCents;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Account ledger {{ $account->account_no }}</title>
{{-- Tables only (no flex/grid): the same in Chrome, in dompdf on plain PHP hosting, and when the browser prints it. --}}
<style>
    @page { size: A4; }
    body { margin: 0; color: #0f1e2e; font: 10.5px/1.45 {!! config('laravel-pdf.driver') === 'dompdf' ? "'DejaVu Sans', " : '' !!}Arial, sans-serif; }
    table { border-collapse: collapse; width: 100%; }
    .top td { vertical-align: middle; padding: 0 0 10px; border-bottom: 3px solid #0B7A48; }
    .logo { height: 46px; }
    .title { text-align: right; }
    .title b { display: block; color: #0B3466; font-size: 18px; letter-spacing: .05em; }
    .muted { color: #52637a; }
    .label { display: block; color: #52637a; font-size: 8.5px; text-transform: uppercase; letter-spacing: .05em; }
    .info { margin-top: 12px; }
    .info td { padding: 7px 9px; background: #F5F9FC; border: 1px solid #DCE5EE; vertical-align: top; }
    .info b { font-size: 11.5px; }
    .balance { text-align: right; }
    .balance b { display: block; font-size: 17px; }
    .owed b { color: #C2362F; } .clear b { color: #0B7A48; }
    .sum { margin-top: 10px; }
    .sum td { padding: 7px 9px; border: 1px solid #DCE5EE; width: 25%; }
    .sum b { display: block; font-size: 12.5px; }
    .ledger { margin-top: 12px; }
    .ledger th { background: #0B3466; color: #fff; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .05em; padding: 6px 7px; }
    .ledger td { padding: 5px 7px; border-bottom: 1px solid #E3EAF1; vertical-align: top; }
    .ledger tr { page-break-inside: avoid; }
    .ledger .num { text-align: right; white-space: nowrap; }
    .ledger .month td { background: #F5F9FC; color: #52637a; font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; padding: 4px 7px; }
    .ledger .start td { background: #F5F9FC; font-weight: bold; }
    .ledger .end td { background: #0B3466; color: #fff; font-weight: bold; padding: 7px; }
    .tag { font-weight: bold; color: #0B3466; }
    .tag.pay { color: #0B7A48; }
    .tag.rev { color: #C2362F; }
    .paid { color: #0B7A48; }
    .receipt { color: #52637a; font-size: 9px; }
    .note { margin-top: 12px; padding: 8px 10px; border: 1px solid #DCE5EE; color: #52637a; font-size: 9.5px; }
</style>
</head>
<body>
    <table class="top">
        <tr>
            <td>
                <img class="logo" src="{{ $logo }}" alt="{{ $co['company_name'] }}">
                <div class="muted" style="font-size:9.5px">{{ $co['company_name'] }}@if ($co['company_address']) · {{ $co['company_address'] }}@endif</div>
            </td>
            <td class="title"><b>ACCOUNT LEDGER</b><span class="muted">{{ $statement->from->format('d M Y') }} – {{ $statement->to->format('d M Y') }}</span></td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td style="width:34%"><span class="label">Boarder</span><b>{{ $account->payer->full_name }}</b></td>
            <td style="width:16%"><span class="label">Account No.</span><b>{{ $account->account_no }}</b></td>
            <td style="width:28%"><span class="label">Room</span><b>{{ $rooms ?: '—' }}</b></td>
            <td class="balance {{ $closing > 0 ? 'owed' : 'clear' }}">
                <span class="label">{{ $closing > 0 ? 'Balance owed' : ($closing < 0 ? 'Credit' : 'Balance') }} · {{ $statement->to->format('d M Y') }}</span>
                <b>{{ $peso(abs($closing)) }}</b>
            </td>
        </tr>
    </table>

    <table class="sum">
        <tr>
            <td><span class="label">Balance on {{ $statement->from->format('d M Y') }}</span><b>{{ $peso($statement->openingCents) }}</b></td>
            <td><span class="label">Bills added</span><b>+ {{ $peso($charges) }}</b></td>
            <td><span class="label">Payments &amp; credits</span><b class="paid">− {{ $peso($paid) }}</b></td>
            <td><span class="label">Balance on {{ $statement->to->format('d M Y') }}</span><b>{{ $peso($closing) }}</b></td>
        </tr>
    </table>

    <table class="ledger">
        <thead>
            <tr>
                <th style="width:13%">Date</th>
                <th>Details</th>
                <th class="num" style="width:14%">Charges</th>
                <th class="num" style="width:14%">Payments</th>
                <th class="num" style="width:15%">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr class="start">
                <td>{{ $statement->from->format('d M Y') }}</td>
                <td colspan="3">Balance at the start of the period</td>
                <td class="num">{{ $peso($statement->openingCents) }}</td>
            </tr>
            @php($month = null)
            @forelse ($statement->rows as $row)
                @php($m = \Illuminate\Support\Carbon::parse($row['date'])->format('F Y'))
                @if ($m !== $month)
                    @php($month = $m)
                    <tr class="month"><td colspan="5">{{ $m }}</td></tr>
                @endif
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                    <td>
                        <span class="tag {{ in_array($row['type'], ['payment', 'credit'], true) ? 'pay' : ($row['type'] === 'reversal' ? 'rev' : '') }}">{{ $kinds[$row['type']] ?? ucfirst($row['type']) }}</span>
                        @if ($details($row)) · {{ $details($row) }}@endif
                        @if (! empty($row['receipt_number']))<br><span class="receipt">Receipt No. {{ $row['receipt_number'] }}</span>@endif
                    </td>
                    <td class="num">{{ $row['amount_cents'] > 0 ? $peso($row['amount_cents']) : '' }}</td>
                    <td class="num paid">{{ $row['amount_cents'] < 0 ? $peso(-$row['amount_cents']) : '' }}</td>
                    <td class="num"><b>{{ $peso($row['balance_cents']) }}</b></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted" style="text-align:center; padding:14px">Nothing was billed or paid in this period.</td></tr>
            @endforelse
            <tr class="end">
                <td colspan="2">Balance on {{ $statement->to->format('d M Y') }}</td>
                <td class="num">{{ $peso($charges) }}</td>
                <td class="num">{{ $peso($paid) }}</td>
                <td class="num">{{ $peso($closing) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        A balance above zero is owed to {{ $co['company_name'] }}; below zero is credit that goes on the next bill.
        Printed {{ today()->format('d M Y') }}. Keep your payment receipts; each receipt number is listed above.
        @if ($co['company_phone'] || $co['company_email']) Questions: {{ $co['company_phone'] }}@if ($co['company_phone'] && $co['company_email']) · @endif{{ $co['company_email'] }}@endif
    </div>
</body>
</html>
