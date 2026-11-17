@php
    use App\Support\Money;
    $logo = 'data:image/png;base64,'.base64_encode((string) @file_get_contents(resource_path('img/mubarak-logo-horizontal-pdf.png')));
    $tenant = $payment->billingAccount->payer->full_name;
    $reversed = $payment->status === 'reversed';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment receipt {{ $payment->ack_number }}</title>
    {{-- Tables only (no flex/grid): the same in Chrome, in dompdf on plain PHP hosting, and when the browser prints it. --}}
    <style>
        @page { size: A4; }
        body { margin: 0; color: #0f1e2e; font: 12px/1.5 {!! config('laravel-pdf.driver') === 'dompdf' ? "'DejaVu Sans', " : '' !!}Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; }
        .top td { vertical-align: middle; padding: 0 0 14px; border-bottom: 4px solid #0B7A48; }
        .logo { height: 58px; }
        .title { text-align: right; }
        .title b { display: block; color: #0B3466; font-size: 22px; letter-spacing: .04em; }
        .muted { color: #52637a; }
        .meta { margin-top: 16px; }
        .meta td { padding: 8px 10px; background: #F5F9FC; border: 1px solid #DCE5EE; width: 33%; }
        .label { display: block; color: #52637a; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
        .meta b { font-size: 14px; }
        .amount { margin: 16px 0; background: #E6F4EC; border: 1.5px solid #13A35C; padding: 14px 16px; }
        .amount b { display: block; color: #0B3466; font-size: 28px; }
        .details th, .details td { border-bottom: 1px solid #dce5ee; text-align: left; padding: 7px 4px; vertical-align: top; }
        .details th { width: 32%; color: #52637a; font-weight: normal; }
        h2 { color: #0B3466; font-size: 14px; margin: 18px 0 6px; }
        .applied td { border-bottom: 1px solid #dce5ee; padding: 6px 4px; }
        .amt { text-align: right; white-space: nowrap; }
        .balance td { padding: 10px; font-weight: bold; background: #0B3466; color: #fff; }
        .balance.credit td { background: #0B7A48; }
        .reversed { margin-top: 12px; padding: 10px; border: 1px solid #C2362F; background: #FBEAE9; color: #C2362F; font-weight: bold; }
        .sign td { padding-top: 34px; width: 50%; }
        .sign span { display: block; border-top: 1px solid #0f1e2e; width: 70%; padding-top: 4px; font-size: 11px; color: #52637a; }
        .notice { margin-top: 18px; padding: 10px; border: 1px solid #dce5ee; font-size: 11px; color: #52637a; }
    </style>
</head>
<body>
    <table class="top">
        <tr>
            <td>
                <img class="logo" src="{{ $logo }}" alt="{{ $company['company_name'] }}">
                <div>{{ $company['company_name'] }}</div>
                <div class="muted">{{ $company['company_address'] }}</div>
            </td>
            <td class="title"><b>PAYMENT RECEIPT</b><span class="muted">Thank you for your payment.</span></td>
        </tr>
    </table>

    @if ($reversed)
        <div class="reversed">REVERSED — this payment was cancelled and no longer counts. Reason: {{ $payment->reverse_reason }}</div>
    @endif

    <table class="meta">
        <tr>
            <td><span class="label">Receipt No.</span><b>{{ $payment->ack_number }}</b></td>
            <td><span class="label">Date paid</span><b>{{ $payment->paid_on->format('d M Y') }}</b></td>
            <td><span class="label">Account No.</span><b>{{ $payment->billingAccount->account_no }}</b></td>
        </tr>
    </table>

    <div class="amount"><span class="label">Amount received</span><b>{{ Money::format($payment->amount_cents) }}</b></div>

    <table class="details">
        <tr><th>Received from</th><td>{{ $payment->payer_name ?: $tenant }}</td></tr>
        @if ($payment->payer_name && $payment->payer_name !== $tenant)<tr><th>For boarder</th><td>{{ $tenant }}</td></tr>@endif
        <tr><th>Property</th><td>{{ $payment->property->name }}</td></tr>
        <tr><th>Paid by</th><td>{{ ucwords(str_replace('_', ' ', $payment->method)) }}@if ($payment->reference) · Ref. {{ $payment->reference }}@endif</td></tr>
        <tr><th>Received by</th><td>{{ $payment->collector?->name ?? 'System' }}@if ($payment->approver && $payment->approver->id !== $payment->collector?->id) · approved by {{ $payment->approver->name }}@endif</td></tr>
        @if ($payment->notes)<tr><th>Notes</th><td>{{ $payment->notes }}</td></tr>@endif
    </table>

    @if (! $reversed && count($appliedTo))
        <h2>This payment paid for</h2>
        <table class="applied">
            @foreach ($appliedTo as $line)
                <tr><td>{{ $line['label'] }}</td><td class="amt">{{ Money::format($line['amount_cents']) }}</td></tr>
            @endforeach
            @php($extra = $payment->amount_cents - array_sum(array_column($appliedTo, 'amount_cents')))
            @if ($extra > 0)
                <tr><td>Kept as credit for the next bill</td><td class="amt">{{ Money::format($extra) }}</td></tr>
            @endif
        </table>
    @endif

    @if (! $reversed && $balanceAfter !== null)
        <table class="balance {{ $balanceAfter < 0 ? 'credit' : '' }}" style="margin-top:14px">
            <tr>
                <td>{{ $balanceAfter < 0 ? 'Credit left after this payment' : ($balanceAfter === 0 ? 'Fully paid after this payment' : 'Balance still owed after this payment') }}</td>
                <td class="amt">{{ Money::format(abs($balanceAfter)) }}</td>
            </tr>
        </table>
    @endif

    <table class="sign">
        <tr>
            <td><span>Received by (signature)</span></td>
            <td><span>Boarder (signature)</span></td>
        </tr>
    </table>

    <div class="notice">
        This receipt confirms a payment recorded in the boarding house's account of {{ $tenant }}. It is not a BIR official receipt or tax invoice.
        @if ($company['company_phone'] || $company['company_email'])
            Questions: {{ $company['company_phone'] }}@if ($company['company_phone'] && $company['company_email']) · @endif{{ $company['company_email'] }}
        @endif
    </div>
</body>
</html>
