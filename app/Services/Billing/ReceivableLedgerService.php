<?php

namespace App\Services\Billing;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The accounts-receivable ledger. Sign: + increases what the tenant owes, - decreases it.
 * Entries are immutable; corrections are reversal entries.
 */
class ReceivableLedgerService
{
    public function post(BillingAccount $acc, LedgerEntryType $type, int $cents, CarbonInterface $effective, Model $source, ?string $memo = null): LedgerEntry
    {
        if ($type === LedgerEntryType::Reversal) {
            throw new InvalidArgumentException('Reversals are created only by reverse().');
        }
        if ($cents === 0) {
            throw ValidationException::withMessages(['amount' => 'A ledger entry cannot be zero.']);
        }

        return DB::transaction(function () use ($acc, $type, $cents, $effective, $source, $memo) {
            $entry = LedgerEntry::create([
                'billing_account_id' => $acc->id,
                'type' => $type,
                'amount_cents' => $cents,
                'effective_date' => $effective->toDateString(),
                'posted_at' => now(),
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'invoice_id' => $source instanceof Invoice ? $source->id : null,
                'memo' => $memo,
                'created_by' => auth()->id(),
            ]);

            Audit::log('ledger.posted', $entry, ['type' => $type->value, 'amount_cents' => $cents, 'effective_date' => $effective->toDateString()]);

            return $entry;
        });
    }

    /** Sum of signed entries; with $asOf only entries effective on or before that date. */
    public function balance(BillingAccount $acc, ?CarbonInterface $asOf = null): int
    {
        return (int) LedgerEntry::where('billing_account_id', $acc->id)
            ->when($asOf, fn ($q) => $q->where('effective_date', '<=', $asOf->toDateString()))
            ->sum('amount_cents');
    }

    /** Post the negated amount, linked by reverses_id. An entry can be reversed once; a reversal cannot be reversed. */
    public function reverse(LedgerEntry $e, string $reason): LedgerEntry
    {
        try {
            return $this->postReversal($e, $reason);
        } catch (UniqueConstraintViolationException) {
            // A concurrent reverse() won the reverses_id unique key.
            throw ValidationException::withMessages(['entry' => 'This entry has already been reversed.']);
        }
    }

    private function postReversal(LedgerEntry $e, string $reason): LedgerEntry
    {
        return DB::transaction(function () use ($e, $reason) {
            $e = LedgerEntry::lockForUpdate()->findOrFail($e->id);

            if ($e->type === LedgerEntryType::Reversal) {
                throw ValidationException::withMessages(['entry' => 'A reversal cannot be reversed — post a new entry instead.']);
            }
            if (LedgerEntry::where('reverses_id', $e->id)->exists()) {
                throw ValidationException::withMessages(['entry' => 'This entry has already been reversed.']);
            }

            $reversal = LedgerEntry::create([
                'billing_account_id' => $e->billing_account_id,
                'type' => LedgerEntryType::Reversal,
                'amount_cents' => -$e->amount_cents,
                'effective_date' => now()->toDateString(),
                'posted_at' => now(),
                'source_type' => $e->source_type,
                'source_id' => $e->source_id,
                'invoice_id' => $e->invoice_id,
                'reverses_id' => $e->id,
                'memo' => $reason,
                'created_by' => auth()->id(),
            ]);

            Audit::log('ledger.reversed', $reversal, ['reverses_id' => $e->id, 'amount_cents' => $reversal->amount_cents, 'reason' => $reason]);

            return $reversal;
        });
    }
}
