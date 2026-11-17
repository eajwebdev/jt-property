<?php

namespace App\Http\Controllers;

use App\Models\BillingAccount;
use App\Models\Payment;
use App\Rules\Pesos;
use App\Services\Billing\PaymentAcknowledgmentService;
use App\Services\Billing\PaymentService;
use App\Support\Money;
use App\Support\PdfDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $service) {}

    public function store(Request $request, BillingAccount $account): RedirectResponse
    {
        Gate::authorize('create', [Payment::class, $account->property_id]);

        $data = $request->validate([
            'amount' => ['required', 'string', new Pesos('payment amount')],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', 'in:'.implode(',', Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'payer_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $proof = $request->hasFile('proof') ? $request->file('proof')->store('payment-proofs', 'local') : null;

        try {
            $payment = $this->service->record($account, [
                'amount_cents' => Money::toCents($data['amount']),
                'proof_path' => $proof,
            ] + $data, $request->user());
        } catch (Throwable $e) {
            // Nothing was saved, so the uploaded file would be orphaned.
            if ($proof !== null) {
                Storage::disk('local')->delete($proof);
            }
            throw $e;
        }

        return redirect()->route('payments.show', $payment)->with('success', $payment->status === 'approved'
            ? Money::format($payment->amount_cents).' payment recorded. Receipt '.$payment->ack_number.' is ready: print it or give it to the boarder.'
            : 'Payment saved. It counts, and its receipt is issued, once the owner approves it.');
    }

    /** What an amount would settle, oldest first; the modal shows this before saving. */
    public function preview(Request $request, BillingAccount $account): JsonResponse
    {
        Gate::authorize('create', [Payment::class, $account->property_id]);

        $request->validate(['amount' => ['required', 'string', new Pesos('payment amount')]]);
        $cents = Money::toCents($request->query('amount'));
        $plan = $this->service->plan($account, $cents);
        $debits = $this->service->openDebits([$account->id])->keyBy('id');

        return response()->json([
            'amount_cents' => $cents,
            'lines' => $plan->map(fn (int $apply, int $id) => [
                'ledger_entry_id' => $id,
                'label' => $debits[$id]->memo ?: ucfirst($debits[$id]->type->value),
                'due_date' => $debits[$id]->due_date,
                'remaining_cents' => $debits[$id]->remaining_cents,
                'apply_cents' => $apply,
            ])->values(),
            'credit_cents' => $cents - $plan->sum(),
        ]);
    }

    public function show(Payment $payment): Response
    {
        Gate::authorize('view', $payment);

        $payment->load(['billingAccount.payer:id,full_name', 'property:id,name,code', 'collector:id,name', 'approver:id,name', 'allocations.ledgerEntry']);
        $user = request()->user();

        return Inertia::render('payments/show', [
            'payment' => [
                'id' => $payment->id,
                'account_id' => $payment->billing_account_id,
                'account_no' => $payment->billingAccount->account_no,
                'tenant' => $payment->billingAccount->payer->full_name,
                'property' => $payment->property->name,
                'amount_cents' => $payment->amount_cents,
                'amount' => Money::format($payment->amount_cents),
                'paid_on' => $payment->paid_on->toDateString(),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'payer_name' => $payment->payer_name,
                'notes' => $payment->notes,
                'status' => $payment->status,
                'ack_number' => $payment->ack_number,
                'collected_by' => $payment->collector?->name,
                'approved_by' => $payment->approver?->name,
                'has_proof' => $payment->proof_path !== null,
                'reverse_reason' => $payment->reverse_reason,
                'unallocated' => Money::format($this->service->unallocated($payment)),
                'unallocated_cents' => $this->service->unallocated($payment),
            ],
            'openDebits' => $payment->status === 'approved' && $user->can('allocate', $payment)
                ? $this->service->openDebits([$payment->billing_account_id])->map(fn ($d) => [
                    'id' => $d->id,
                    'label' => ($d->memo ?: ucfirst($d->type->value)).' due '.Carbon::parse($d->due_date)->format('M j, Y'),
                    'remaining_cents' => $d->remaining_cents,
                    'remaining' => Money::format($d->remaining_cents),
                ])->values()
                : [],
            'allocations' => $payment->allocations->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->ledgerEntry->memo ?: ucfirst($a->ledgerEntry->type->value),
                'date' => $a->ledgerEntry->effective_date->toDateString(),
                'amount' => Money::format($a->amount_cents),
                'reversed' => $a->reversed_at !== null,
            ])->values(),
            'canManage' => $user->can('approve', $payment),
        ]);
    }

    public function proof(Payment $payment): StreamedResponse
    {
        Gate::authorize('view', $payment);
        abort_if($payment->proof_path === null || ! Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->download($payment->proof_path);
    }

    public function acknowledgment(Payment $payment, PaymentAcknowledgmentService $documents): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('view', $payment);
        abort_if($payment->ack_number === null, 404);

        return PdfDocument::respond($documents->html($payment), $payment->ack_number.'.pdf', fn () => $documents->pdf($payment));
    }

    public function approve(Payment $payment): RedirectResponse
    {
        Gate::authorize('approve', $payment);

        $payment = $this->service->approve($payment, request()->user());

        return back()->with('success', 'Payment approved. Receipt '.$payment->ack_number.' is ready to print.');
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('reverse', $payment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->service->reverse($payment, trim($data['reason']));

        return back()->with('success', 'Payment reversed. The charges it covered are unpaid again.');
    }

    public function allocate(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('allocate', $payment);

        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['required', 'string', new Pesos('allocation')],
        ]);
        $this->service->allocate($payment, array_map(fn ($v) => Money::toCents($v), $data['allocations']));

        return back()->with('success', 'Allocation saved.');
    }

    public function refund(Request $request, BillingAccount $account): RedirectResponse
    {
        Gate::authorize('refund', Payment::class);

        $data = $request->validate(['amount' => ['required', 'string', new Pesos('refund')], 'reason' => ['required', 'string', 'max:1000']]);
        $this->service->refund($account, Money::toCents($data['amount']), trim($data['reason']));

        return back()->with('success', 'Refund recorded.');
    }
}
