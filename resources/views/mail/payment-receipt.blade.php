@php use App\Support\Money; @endphp
<p>Hello {{ $payment->billingAccount->payer->full_name }},</p>
<p>Thank you for your payment. Your receipt <strong>{{ $payment->ack_number }}</strong> is attached as a PDF.</p>
<p>Amount received: <strong>{{ Money::format($payment->amount_cents) }}</strong><br>
Date paid: {{ $payment->paid_on->format('d M Y') }}<br>
Account number: {{ $payment->billingAccount->account_no }}<br>
Paid by: {{ ucwords(str_replace('_', ' ', $payment->method)) }}@if ($payment->reference) (Ref. {{ $payment->reference }})@endif</p>
<p>Please keep this receipt for your records. If anything looks wrong, reply to this email or contact the office.</p>
<p>{{ $company['company_name'] }}@if ($company['company_address'])<br>{{ $company['company_address'] }}@endif @if ($company['company_phone'])<br>{{ $company['company_phone'] }}@endif</p>
