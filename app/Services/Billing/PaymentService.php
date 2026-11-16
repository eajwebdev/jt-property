<?php

namespace App\Services\Billing;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payments against a billing account. An approved payment posts exactly ONE negative `payment` ledger entry;
 * allocations only record which debts it settles and never touch the ledger, so nothing is subtracted twice.
 * Callers authorize (owner approves, reverses, refunds, writes off; staff only record).
 */
class PaymentService
{
    public function __construct(private ReceivableLedgerService $ledger) {}

    /**
     * Staff payments wait as `pending` (nothing posted); an owner's payment is approved at once.
     * $data: amount_cents, paid_on, method, reference?, payer_name?, notes?, proof_path?, allocations? [ledger_entry_id => cents].
     */
    public function record(BillingAccount $account, array $data, User $by): Payment
    {
        $cents = (int) ($data['amount_cents'] ?? 0);
        $paidOn = Carbon::parse($data['paid_on']);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'The payment must be more than zero.']);
        }
        if ($paidOn->toDateString() > today()->toDateString()) {
            throw ValidationException::withMessages(['paid_on' => 'The payment date cannot be in the future.']);
        }
        if (! in_array($data['method'] ?? null, Payment::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'Choose how the payment was made.']);
        }

        try {
            return DB::transaction(function () use ($account, $data, $by, $cents, $paidOn) {
                $payment = Payment::create([
                    'billing_account_id' => $account->id,
                    'property_id' => $account->property_id,
                    'amount_cents' => $cents,
                    'paid_on' => $paidOn->toDateString(),
                    'method' => $data['method'],
                    'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'payer_name' => filled($data['payer_name'] ?? null) ? trim($data['payer_name']) : null,
                    'collected_by' => $by->id,
                    'status' => 'pending',
                    'proof_path' => $data['proof_path'] ?? null,
                    'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
                ]);
                Audit::log('payment.recorded', $payment, ['amount_cents' => $cents, 'method' => $payment->method, 'by_role' => $by->role->value]);

                if ($by->isOwner()) {
                    $this->approve($payment, $by, $data['allocations'] ?? null);
                }

                return $payment->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => 'This reference number was already used for this payment method.']);
        }
    }

    /**
     * Posts the one ledger entry and allocates (the given manual split, or oldest-first). Approving twice is a no-op.
     *
     * @param  array<int, int>|null  $manual  ledger_entry_id => cents
     */
    public function approve(Payment $payment, User $by, ?array $manual = null): Payment
    {
        DB::transaction(function () use ($payment, $by, $manual) {
            $row = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($row->status === 'approved') {
                return;
            }
            if ($row->status === 'reversed') {
                throw ValidationException::withMessages(['payment' => 'This payment was reversed.']);
            }

            $this->lockAccount($row->billing_account_id);
            $entry = $this->ledger->post($row->billingAccount, LedgerEntryType::Payment, -$row->amount_cents, $row->paid_on, $row, 'Payment '.$row->method);
            $row->update([
                'status' => 'approved',
                'approved_by' => $by->id,
                'ledger_entry_id' => $entry->id,
                'ack_number' => $this->nextAckNumber($row),
            ]);
            Audit::log('payment.approved', $row, ['amount_cents' => $row->amount_cents, 'ledger_entry_id' => $entry->id, 'ack_number' => $row->ack_number]);

            $this->allocateWithin($row, $manual !== null && $manual !== [] ? $manual : $this->plan($row->billingAccount, $row->amount_cents)->all());
            // A manual split can leave part of the payment unallocated; put it to work on what is still owed.
            $this->applyCredit($row->billingAccount);
        });

        return $payment->refresh();
    }

    /**
     * Adds manual allocations to an approved payment, within what it has left and what each debt still owes.
     *
     * @param  array<int, int>  $manual  ledger_entry_id => cents
     */
    public function allocate(Payment $payment, array $manual): Payment
    {
        DB::transaction(function () use ($payment, $manual) {
            $row = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($row->status !== 'approved') {
                throw ValidationException::withMessages(['allocations' => 'Only an approved payment can be allocated.']);
            }
            $this->lockAccount($row->billing_account_id);
            $this->allocateWithin($row, $manual);
        });

        return $payment->refresh();
    }

    /** Unallocated part of the payment (stays as credit on the account). */
    public function unallocated(Payment $payment): int
    {
        return $payment->amount_cents - (int) $payment->allocations()->whereNull('reversed_at')->sum('amount_cents');
    }

    /** Reverses the ledger entry; allocation rows stay but are marked reversed, so the debts reopen. */
    public function reverse(Payment $payment, string $reason): Payment
    {
        DB::transaction(function () use ($payment, $reason) {
            $row = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($row->status !== 'approved') {
                throw ValidationException::withMessages(['payment' => $row->status === 'reversed' ? 'This payment was already reversed.' : 'Only an approved payment can be reversed.']);
            }
            $this->lockAccount($row->billing_account_id);

            $this->ledger->reverse(LedgerEntry::findOrFail($row->ledger_entry_id), $reason);
            $row->allocations()->whereNull('reversed_at')->update(['reversed_at' => now()]);
            $row->update(['status' => 'reversed', 'reversed_at' => now(), 'reverse_reason' => $reason]);
            Audit::log('payment.reversed', $row, ['reason' => $reason, 'amount_cents' => $row->amount_cents]);
        });

        return $payment->refresh();
    }

    /** Hands money back, only up to the account's existing credit. */
    public function refund(BillingAccount $account, int $cents, string $reason): LedgerEntry
    {
        return DB::transaction(function () use ($account, $cents, $reason) {
            $this->lockAccount($account->id);
            $credit = max(0, -$this->ledger->balance($account));
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'The refund must be more than zero.']);
            }
            if ($cents > $credit) {
                throw ValidationException::withMessages(['amount' => 'You can refund at most the credit on this account.']);
            }

            $entry = $this->ledger->post($account, LedgerEntryType::Refund, $cents, today(), $account, $reason);
            Audit::log('payment.refunded', $account, ['amount_cents' => $cents, 'reason' => $reason, 'ledger_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /** Forgives part or all of one debt; the negative `writeoff` entry is tied to that debt. */
    public function writeOff(LedgerEntry $debit, int $cents, string $reason): LedgerEntry
    {
        return DB::transaction(function () use ($debit, $cents, $reason) {
            $this->lockAccount($debit->billing_account_id);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'The write-off must be more than zero.']);
            }
            if ($cents > $this->remaining($debit)) {
                throw ValidationException::withMessages(['amount' => 'You can write off at most what is still unpaid on this charge.']);
            }

            $entry = $this->ledger->post($debit->billingAccount, LedgerEntryType::Writeoff, -$cents, today(), $debit, $reason);
            Audit::log('payment.written_off', $debit, ['amount_cents' => $cents, 'reason' => $reason, 'ledger_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /** What is still owed on one debit entry: amount - active allocations - write-offs. Zero if not a live debit. */
    public function remaining(LedgerEntry $debit): int
    {
        return (int) ($this->debitQuery([$debit->billing_account_id])->where('ledger_entries.id', $debit->id)->value('remaining_cents') ?? 0);
    }

    /**
     * Debts still owing, oldest effective_date first then id. Each entry carries `remaining_cents` and `due_date`
     * (invoice due date, or the entry date for an opening balance).
     *
     * @param  list<int>  $accountIds
     * @return Collection<int, LedgerEntry>
     */
    public function openDebits(array $accountIds): Collection
    {
        return $this->debitQuery($accountIds)->get()
            ->each(function (LedgerEntry $e) {
                $e->remaining_cents = (int) $e->remaining_cents;
                $e->due_date = $e->due_date ?? $e->effective_date->toDateString();
            })
            ->filter(fn (LedgerEntry $e) => $e->remaining_cents > 0)
            ->values();
    }

    /**
     * Debts as they stood at the end of $asOf (for reports "as of" a past date): only charges dated on or before it,
     * less payments paid on or before it, write-offs dated on or before it; reversals dated later are ignored.
     * Read-only; payment allocation keeps using openDebits().
     *
     * @param  list<int>  $accountIds
     * @return Collection<int, LedgerEntry>
     */
    public function openDebitsAsOf(array $accountIds, CarbonInterface $asOf): Collection
    {
        $date = $asOf->toDateString();
        $writtenOff = '(SELECT -SUM(w.amount_cents) FROM ledger_entries w WHERE w.type = ? AND w.source_type = ? AND w.source_id = ledger_entries.id AND w.effective_date <= ?'
            .' AND NOT EXISTS (SELECT 1 FROM ledger_entries r WHERE r.reverses_id = w.id AND r.effective_date <= ?))';
        $allocated = '(SELECT SUM(pa.amount_cents) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id'
            .' WHERE pa.ledger_entry_id = ledger_entries.id AND p.paid_on <= ? AND (pa.reversed_at IS NULL OR DATE(pa.reversed_at) > ?))';

        return LedgerEntry::query()
            ->leftJoin('invoices', 'invoices.id', '=', 'ledger_entries.invoice_id')
            ->whereIn('ledger_entries.billing_account_id', $accountIds)
            ->whereIn('ledger_entries.type', [LedgerEntryType::Charge->value, LedgerEntryType::Opening->value])
            ->where('ledger_entries.amount_cents', '>', 0)
            ->where('ledger_entries.effective_date', '<=', $date)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as rev')->whereColumn('rev.reverses_id', 'ledger_entries.id')->where('rev.effective_date', '<=', $date))
            ->select('ledger_entries.*', 'invoices.due_date as due_date')
            ->selectRaw("ledger_entries.amount_cents - COALESCE({$allocated}, 0) - COALESCE({$writtenOff}, 0) as remaining_cents", [$date, $date, LedgerEntryType::Writeoff->value, (new LedgerEntry)->getMorphClass(), $date, $date])
            ->orderBy('ledger_entries.effective_date')->orderBy('ledger_entries.id')
            ->get()
            ->each(function (LedgerEntry $e) {
                $e->remaining_cents = (int) $e->remaining_cents;
                $e->due_date = $e->due_date ?? $e->effective_date->toDateString();
            })
            ->filter(fn (LedgerEntry $e) => $e->remaining_cents > 0)
            ->values();
    }

    /**
     * Oldest-first split of an amount: [ledger_entry_id => cents]. What is left over is credit. Used by approve and the preview.
     *
     * @return Collection<int, int>
     */
    public function plan(BillingAccount $account, int $cents): Collection
    {
        $left = $cents;
        $plan = collect();
        foreach ($this->openDebits([$account->id]) as $debit) {
            if ($left <= 0) {
                break;
            }
            $apply = min($left, $debit->remaining_cents);
            $plan[$debit->id] = $apply;
            $left -= $apply;
        }

        return $plan;
    }

    /** @param  array<int, int>  $lines */
    private function allocateWithin(Payment $payment, array $lines): void
    {
        $lines = array_filter($lines, fn ($c) => (int) $c !== 0);
        if ($lines === []) {
            return;
        }

        $open = $this->openDebits([$payment->billing_account_id])->keyBy('id');
        $room = $this->unallocated($payment);

        foreach ($lines as $entryId => $cents) {
            $cents = (int) $cents;
            $debit = $open->get((int) $entryId);
            if ($cents < 0 || ! $debit) {
                throw ValidationException::withMessages(['allocations' => 'You can only allocate to a charge that is still unpaid on this account.']);
            }
            if ($cents > $debit->remaining_cents) {
                throw ValidationException::withMessages(['allocations' => 'You cannot allocate more than the charge still owes.']);
            }
            if ($cents > $room) {
                throw ValidationException::withMessages(['allocations' => 'You cannot allocate more than the payment amount.']);
            }
            PaymentAllocation::create(['payment_id' => $payment->id, 'ledger_entry_id' => $debit->id, 'amount_cents' => $cents]);
            $room -= $cents;
        }
        Audit::log('payment.allocated', $payment, ['allocations' => $lines]);
    }

    /**
     * Puts unallocated money from approved payments (oldest paid_on, then id) onto open debts, oldest debt first.
     * Call after anything that creates a debt (a charge or an opening balance).
     */
    public function applyCredit(BillingAccount $account): void
    {
        DB::transaction(function () use ($account) {
            $this->lockAccount($account->id);
            $payments = Payment::where('billing_account_id', $account->id)->where('status', 'approved')->orderBy('paid_on')->orderBy('id')->get();
            foreach ($payments as $payment) {
                $left = $this->unallocated($payment);
                if ($left <= 0) {
                    continue;
                }
                $plan = $this->plan($account, $left);
                if ($plan->isEmpty()) {
                    return;
                }
                $this->allocateWithin($payment, $plan->all());
            }
        });
    }

    /** What was forgiven (not paid) on one debt, to show next to a bill that reads Paid. */
    public function writtenOff(LedgerEntry $debit): int
    {
        return -(int) LedgerEntry::where('type', LedgerEntryType::Writeoff)->where('source_type', $debit->getMorphClass())->where('source_id', $debit->id)
            ->whereDoesntHave('reversal')->sum('amount_cents');
    }

    /** Serialises every money change on one account (payments, allocations, refunds, write-offs, charges, opening balances). */
    public function lockAccount(int $accountId): void
    {
        BillingAccount::lockForUpdate()->findOrFail($accountId);
    }

    private function debitQuery(array $accountIds): Builder
    {
        $writtenOff = '(SELECT -SUM(w.amount_cents) FROM ledger_entries w WHERE w.type = ? AND w.source_type = ? AND w.source_id = ledger_entries.id AND NOT EXISTS (SELECT 1 FROM ledger_entries r WHERE r.reverses_id = w.id))';
        $allocated = '(SELECT SUM(pa.amount_cents) FROM payment_allocations pa WHERE pa.ledger_entry_id = ledger_entries.id AND pa.reversed_at IS NULL)';

        return LedgerEntry::query()
            ->leftJoin('invoices', 'invoices.id', '=', 'ledger_entries.invoice_id')
            ->whereIn('ledger_entries.billing_account_id', $accountIds)
            ->whereIn('ledger_entries.type', [LedgerEntryType::Charge->value, LedgerEntryType::Opening->value])
            ->where('ledger_entries.amount_cents', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as rev')->whereColumn('rev.reverses_id', 'ledger_entries.id'))
            ->select('ledger_entries.*', 'invoices.due_date as due_date')
            ->selectRaw("ledger_entries.amount_cents - COALESCE({$allocated}, 0) - COALESCE({$writtenOff}, 0) as remaining_cents", [LedgerEntryType::Writeoff->value, (new LedgerEntry)->getMorphClass()])
            ->orderBy('ledger_entries.effective_date')->orderBy('ledger_entries.id');
    }

    /** ACK-{PROPERTY CODE}-{YYYY}-{seq 5}; per property and year. Runs inside the caller's transaction. */
    private function nextAckNumber(Payment $payment): string
    {
        $year = (int) $payment->paid_on->format('Y');
        $key = ['property_id' => $payment->property_id, 'year' => $year];

        // Same single-statement upsert + locked read as InvoiceNumberGenerator, so concurrent approvals serialise.
        DB::table('payment_counters')->upsert([$key + ['last_seq' => 1]], ['property_id', 'year'], ['last_seq' => DB::raw('last_seq + 1')]);
        $seq = (int) DB::table('payment_counters')->where($key)->lockForUpdate()->value('last_seq');

        return sprintf('ACK-%s-%d-%05d', $payment->property->code, $year, $seq);
    }
}
