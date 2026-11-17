@php use App\Support\Money; @endphp
<p>Hello {{ $tenantName }},</p>
<p>This is a {{ $stage === 'before' ? 'courtesy reminder about an upcoming payment' : ($stage === 'due' ? 'reminder that your payment is due today' : 'follow-up about an overdue payment') }} for billing statement {{ $invoiceNumber }}.</p>
<p>Due date: {{ \Illuminate\Support\Carbon::parse($dueDate)->format('d M Y') }}<br>Unpaid amount for this statement: <strong>{{ Money::format($remainingCents) }}</strong></p>
<p>Please use the payment instructions in your monthly statement. If you have already paid, contact the property office with your payment reference so the account can be checked.</p>
<p>Mubarak Boardinghouse</p>
