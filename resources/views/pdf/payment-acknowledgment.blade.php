@php
    use App\Support\Money;
    $logo = 'data:image/webp;base64,'.base64_encode((string) @file_get_contents(resource_path('img/mubarak-logo-horizontal-pdf.webp')));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment acknowledgment {{ $payment->ack_number }}</title>
    <style>
        @page { size: A4; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #0f1e2e; font: 12px/1.55 Arial, sans-serif; }
        .top { display: flex; justify-content: space-between; align-items: start; border-bottom: 4px solid #0B7A48; padding-bottom: 20px; }
        .logo { max-width: 220px; max-height: 65px; }
        h1 { color: #0B3466; font-size: 23px; margin: 25px 0 4px; }
        .muted { color: #52637a; }
        .number { font-size: 16px; font-weight: bold; }
        .amount { background: #E6F4EC; padding: 18px; border-radius: 8px; margin: 22px 0; }
        .amount b { color: #0B3466; font-size: 27px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #dce5ee; text-align: left; padding: 10px 4px; vertical-align: top; }
        th { width: 35%; color: #52637a; font-weight: normal; }
        .notice { margin-top: 26px; padding: 14px; border: 1px solid #dce5ee; border-radius: 6px; }
        .reversed { color: #b91c1c; font-weight: bold; }
        footer { margin-top: 30px; border-top: 1px solid #dce5ee; padding-top: 12px; color: #52637a; font-size: 11px; }
    </style>
</head>
<body>
    <div class="top">
        <div><img class="logo" src="{{ $logo }}" alt="Mubarak Boardinghouse"><div>{{ $company['company_name'] }}</div><div class="muted">{{ $company['company_address'] }}</div></div>
        <div style="text-align:right"><div class="number">{{ $payment->ack_number }}</div><div>{{ $payment->paid_on->format('d M Y') }}</div></div>
    </div>
    <h1>Payment acknowledgment</h1>
    <div class="muted">Confirmation of a recorded and approved payment.</div>
    @if ($payment->status === 'reversed')
        <p class="reversed">REVERSED — This payment no longer settles the account. Reason: {{ $payment->reverse_reason }}</p>
    @endif
    <div class="amount"><div>Amount received</div><b>{{ Money::format($payment->amount_cents) }}</b></div>
    <table>
        <tr><th>Account</th><td>{{ $payment->billingAccount->account_no }}</td></tr>
        <tr><th>Tenant</th><td>{{ $payment->billingAccount->payer->full_name }}</td></tr>
        <tr><th>Property</th><td>{{ $payment->property->name }}</td></tr>
        <tr><th>Payer</th><td>{{ $payment->payer_name ?: $payment->billingAccount->payer->full_name }}</td></tr>
        <tr><th>Paid on</th><td>{{ $payment->paid_on->format('d M Y') }}</td></tr>
        <tr><th>Method</th><td>{{ ucwords(str_replace('_', ' ', $payment->method)) }}</td></tr>
        @if ($payment->reference)<tr><th>Transaction reference</th><td>{{ $payment->reference }}</td></tr>@endif
        <tr><th>Recorded by</th><td>{{ $payment->collector?->name ?? 'System' }}</td></tr>
        <tr><th>Approved by</th><td>{{ $payment->approver?->name ?? 'System' }}</td></tr>
    </table>
    <div class="notice">This acknowledgment records a payment in the property management ledger. It is not a BIR official receipt or tax invoice.</div>
    <footer>{{ $company['company_phone'] }} @if ($company['company_phone'] && $company['company_email']) · @endif {{ $company['company_email'] }}</footer>
</body>
</html>
