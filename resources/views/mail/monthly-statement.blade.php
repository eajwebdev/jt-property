@php use App\Support\Money; @endphp
<p>Hello {{ $statement->tenantName }},</p>
<p>Your {{ $statement->periodStart->format('F Y') }} billing statement {{ $statement->number }} for {{ $statement->unitLabel }} is attached as a PDF.</p>
<p>Billing period: {{ $statement->periodStart->format('d M Y') }} – {{ $statement->periodEnd->format('d M Y') }}<br>
Due date: {{ $statement->dueDate->format('d M Y') }}<br>
Amount due on the attached statement: <strong>{{ Money::format($statement->totalDueCents) }}</strong></p>
@if (count($paymentInstructions) > 0)
<p>Payment options:</p><ul>
@foreach ($paymentInstructions as $instruction)
<li>{{ $instruction['label'] }} — {{ $instruction['account_name'] }} · {{ $instruction['account_number'] }} @if (! empty($instruction['notes'])) ({{ $instruction['notes'] }}) @endif</li>
@endforeach
</ul>
@else
<p>Please use the payment instructions on the attached statement or contact the property office.</p>
@endif
<p>Please include your account number {{ $statement->accountNo }} or statement number {{ $statement->number }} with your payment reference.</p>
<p>Mubarak Boardinghouse</p>
