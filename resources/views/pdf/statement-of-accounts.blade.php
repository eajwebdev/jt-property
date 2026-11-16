@php
    $amount = fn (?int $cents) => $cents ? number_format($cents / 100, 2) : '-';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Accounts</title>
    <style>
        @page { size: 13in 8.5in; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; font: 9pt/1.25 Arial, 'Helvetica Neue', sans-serif; -webkit-print-color-adjust: exact; }
        .report + .report { break-before: page; }
        .head { text-align: center; margin-bottom: 8px; }
        .head .name { font-size: 14pt; font-weight: bold; text-transform: uppercase; letter-spacing: .3px; }
        .head .line { font-size: 9pt; }
        .head .title { margin-top: 10px; font-size: 11pt; font-weight: bold; text-decoration: underline; }
        .head .asof { font-size: 9.5pt; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #000; padding: 2px 4px; vertical-align: middle; }
        th { font-weight: normal; text-align: center; font-size: 8.5pt; line-height: 1.15; }
        th.group { letter-spacing: 6px; font-size: 9.5pt; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        td.num { text-align: right; white-space: nowrap; }
        td.no { text-align: right; padding-right: 3px; }
        td.b, th.b { font-weight: bold; }
        tr.section td { font-weight: bold; text-decoration: underline; border-left: 1px solid #000; background: #fff; }
        tr.totals td { font-weight: bold; background: #f1f1f1; border-top: 2px solid #000; }
        td.remarks { font-size: 8.5pt; }
        .empty { padding: 12px; text-align: center; }
    </style>
</head>
<body>
@foreach ($reports as $report)
    @php($p = $report['property'])
    <div class="report">
        <div class="head">
            <div class="name">{{ $p->name }}</div>
            <div class="line">{{ $p->address }}</div>
            @if ($p->dti_registration_no || $p->business_permit_no)
                <div class="line">
                    @if ($p->dti_registration_no) DTI Registration Certificate No. {{ $p->dti_registration_no }}@endif
                    @if ($p->dti_registration_no && $p->business_permit_no); @endif
                    @if ($p->business_permit_no) Business (Mayor's) Permit No. {{ $p->business_permit_no }}@endif
                </div>
            @endif
            <div class="title">STATEMENT OF ACCOUNTS</div>
            <div class="asof">As of {{ $report['as_of']->format('F d, Y') }}</div>
        </div>

        <table>
            <colgroup>
                <col style="width:2.6%"><col style="width:14.5%"><col style="width:12.5%"><col style="width:6%">
                <col style="width:6.4%"><col style="width:6.4%"><col style="width:5.6%"><col style="width:5.6%"><col style="width:5.6%"><col style="width:5.6%">
                <col style="width:6.4%"><col>
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="3">No.</th>
                    <th rowspan="3">Name of Boarders</th>
                    <th rowspan="3">Room Name/Number and Bed Name/Number</th>
                    <th rowspan="3">Monthly Rate</th>
                    <th colspan="7" class="group">ACCOUNTS</th>
                    <th rowspan="3">Remarks</th>
                </tr>
                <tr>
                    <th rowspan="2">Monthly Due on Bed/Room Space Occupation</th>
                    <th rowspan="2">Actual Electric Consumption</th>
                    <th colspan="4">Other Charges</th>
                    <th rowspan="2" class="b">TOTAL</th>
                </tr>
                <tr>
                    <th>One Month Advance</th>
                    <th>One Month Deposit</th>
                    <th>Deposit for the Door Key</th>
                    <th>Others</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['sections'] as $title => $rows)
                    <tr class="section"><td colspan="12">{{ $title }}:</td></tr>
                    @foreach ($rows as $i => $row)
                        <tr>
                            <td class="no">{{ $i + 1 }}.</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['room'] }}</td>
                            <td class="num b">{{ $amount($row['rate_cents']) }}</td>
                            @foreach (\App\Services\Billing\StatementOfAccountsReport::COLUMNS as $column)
                                <td class="num">{{ $amount($row['columns'][$column]) }}</td>
                            @endforeach
                            <td class="num b">{{ $amount($row['total_cents']) }}</td>
                            <td class="remarks">{{ $row['remarks'] }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="12" class="empty">No boarders on record for this property.</td></tr>
                @endforelse
                @if ($report['sections'] !== [])
                    <tr class="totals">
                        <td colspan="3">GRAND TOTAL</td>
                        <td class="num">{{ $amount($report['totals']['rate']) }}</td>
                        @foreach (\App\Services\Billing\StatementOfAccountsReport::COLUMNS as $column)
                            <td class="num">{{ $amount($report['totals'][$column]) }}</td>
                        @endforeach
                        <td class="num">₱{{ number_format($report['totals']['total'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
@endforeach
</body>
</html>
