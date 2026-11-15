<?php

namespace App\Services\Billing;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\OpeningBalance;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Unpaid debt from before the JT system. Posted to the ledger once as an `opening` entry,
 * never billed as rent, never mixed with deposits or advances. Callers authorize (owner only).
 */
class OpeningBalanceService
{
    public const CSV_HEADER = ['account_no', 'amount', 'as_of', 'description'];

    public function __construct(private ReceivableLedgerService $ledger) {}

    /**
     * Saves a pending draft; nothing is posted yet.
     *
     * @param  list<array{month: string, amount_cents: int, note: ?string}>|null  $breakdown
     */
    public function draft(BillingAccount $account, int $cents, CarbonInterface $asOf, ?array $breakdown, ?string $notes, ?string $description = null): OpeningBalance
    {
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'The opening balance must be more than zero.']);
        }
        if ($asOf->toDateString() > today()->toDateString()) {
            throw ValidationException::withMessages(['as_of' => 'The balance date cannot be in the future.']);
        }
        if ($breakdown !== null && $breakdown !== []) {
            if (array_sum(array_column($breakdown, 'amount_cents')) !== $cents) {
                throw ValidationException::withMessages(['breakdown' => 'The monthly amounts must add up to the total balance.']);
            }
        } else {
            $breakdown = null;
        }

        try {
            return DB::transaction(function () use ($account, $cents, $asOf, $breakdown, $notes, $description) {
                $ob = OpeningBalance::create([
                    'billing_account_id' => $account->id,
                    'amount_cents' => $cents,
                    'as_of' => $asOf->toDateString(),
                    'breakdown' => $breakdown,
                    'notes' => $notes ?: null,
                    'status' => 'pending',
                    'created_by' => auth()->id(),
                ] + (filled($description) ? ['description' => $description] : []));
                Audit::log('opening_balance.drafted', $ob, ['amount_cents' => $cents, 'as_of' => $asOf->toDateString(), 'account_no' => $account->account_no]);

                return $ob->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['billing_account_id' => 'This account already has an opening balance. Reverse it first to enter a new one.']);
        }
    }

    /** Posts the one `opening` ledger entry. Approving again returns the same entry. */
    public function approve(OpeningBalance $ob): LedgerEntry
    {
        $entry = DB::transaction(function () use ($ob) {
            $row = OpeningBalance::lockForUpdate()->findOrFail($ob->id);

            if ($row->status === 'approved') {
                return LedgerEntry::findOrFail($row->ledger_entry_id);
            }
            if ($row->status === 'reversed') {
                throw ValidationException::withMessages(['opening_balance' => 'This opening balance was reversed. Enter a new one instead.']);
            }

            $entry = $this->ledger->post($row->billingAccount, LedgerEntryType::Opening, $row->amount_cents, $row->as_of, $row, $row->description);
            $row->update(['status' => 'approved', 'approved_by' => auth()->id(), 'ledger_entry_id' => $entry->id]);
            Audit::log('opening_balance.approved', $row, ['amount_cents' => $row->amount_cents, 'ledger_entry_id' => $entry->id]);

            return $entry;
        });
        $ob->refresh();

        return $entry;
    }

    /** Undoes an approved balance with a ledger reversal, or cancels a pending draft. A new one may then be drafted. */
    public function reverse(OpeningBalance $ob, string $reason): void
    {
        DB::transaction(function () use ($ob, $reason) {
            $row = OpeningBalance::lockForUpdate()->findOrFail($ob->id);

            if ($row->status === 'reversed') {
                throw ValidationException::withMessages(['opening_balance' => 'This opening balance was already reversed.']);
            }
            if ($row->status === 'approved') {
                $this->ledger->reverse(LedgerEntry::findOrFail($row->ledger_entry_id), $reason);
            }

            $was = $row->status;
            $row->update(['status' => 'reversed', 'reversed_at' => now(), 'reverse_reason' => $reason]);
            Audit::log($was === 'pending' ? 'opening_balance.cancelled' : 'opening_balance.reversed', $row, ['was' => $was, 'reason' => $reason, 'amount_cents' => $row->amount_cents]);
        });
        $ob->refresh();
    }

    /**
     * Header `account_no,amount,as_of,description`. Valid rows become pending drafts in one transaction;
     * invalid rows are reported as "Row N (JT-...): reason".
     *
     * @return array{created: int, errors: list<string>}
     */
    public function importCsv(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            return ['created' => 0, 'errors' => ['The file must be saved as UTF-8.']];
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, escape: '');
        if (array_map(fn ($h) => strtolower(trim((string) $h)), $header ?: []) !== self::CSV_HEADER) {
            fclose($stream);

            return ['created' => 0, 'errors' => ['The first line must be: '.implode(',', self::CSV_HEADER)]];
        }

        $errors = [];
        $valid = [];
        $line = 1;
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $line++;
            if ($row === [null]) {
                continue;
            }
            $accountNo = trim((string) ($row[0] ?? ''));
            try {
                $valid[] = ['line' => $line] + $this->validateCsvRow($row, $valid);
            } catch (InvalidArgumentException $e) {
                $errors[] = "Row {$line}".($accountNo !== '' ? " ({$accountNo})" : '').': '.$e->getMessage();
            }
        }
        fclose($stream);

        $created = 0;
        DB::transaction(function () use ($valid, &$created, &$errors) {
            foreach ($valid as $v) {
                try {
                    $this->draft($v['account'], $v['cents'], $v['as_of'], null, null, $v['description']);
                    $created++;
                } catch (ValidationException $e) {
                    $errors[] = "Row {$v['line']} ({$v['account']->account_no}): ".collect($e->errors())->flatten()->first();
                }
            }
        });

        return ['created' => $created, 'errors' => $errors];
    }

    /** @return array{account: BillingAccount, cents: int, as_of: Carbon, description: ?string} */
    private function validateCsvRow(array $row, array $accepted): array
    {
        $accountNo = trim((string) ($row[0] ?? ''));
        $account = $accountNo === '' ? null : BillingAccount::where('account_no', $accountNo)->first();
        if (! $account) {
            throw new InvalidArgumentException('account number not found.');
        }
        if (collect($accepted)->contains(fn ($a) => $a['account']->id === $account->id)) {
            throw new InvalidArgumentException('this account appears more than once in the file.');
        }
        if (OpeningBalance::where('billing_account_id', $account->id)->where('status', '!=', 'reversed')->exists()) {
            throw new InvalidArgumentException('this account already has an opening balance.');
        }

        try {
            $cents = Money::toCents(trim((string) ($row[1] ?? '')));
        } catch (InvalidArgumentException) {
            $cents = 0;
        }
        if ($cents <= 0) {
            throw new InvalidArgumentException('amount "'.($row[1] ?? '').'" is not a valid peso amount (use 1800 or 1,800.00).');
        }

        $text = trim((string) ($row[2] ?? ''));
        $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? Carbon::createFromFormat('!Y-m-d', $text) : false;
        if (! $asOf || $asOf->toDateString() !== $text) {
            throw new InvalidArgumentException('date "'.$text.'" must look like 2026-10-31.');
        }
        if ($text > today()->toDateString()) {
            throw new InvalidArgumentException('the date cannot be in the future.');
        }

        $description = trim((string) ($row[3] ?? ''));
        if (mb_strlen($description) > 255) {
            throw new InvalidArgumentException('description is longer than 255 characters.');
        }

        return ['account' => $account, 'cents' => $cents, 'as_of' => $asOf, 'description' => $description ?: null];
    }
}
