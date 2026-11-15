<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Invoice extends Model
{
    /** The only columns that may change, and only on the finalized -> void transition. */
    private const VOID_COLUMNS = ['status', 'voided_at', 'void_reason', 'voided_by', 'updated_at'];

    protected $fillable = [
        'billing_account_id', 'rental_agreement_id', 'billing_batch_id', 'property_id', 'number',
        'period_start', 'period_end', 'issue_date', 'due_date', 'status', 'current_charges_cents',
        'idempotency_key', 'finalized_at', 'voided_at', 'void_reason', 'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'current_charges_cents' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'issue_date' => 'date',
            'due_date' => 'date',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            $isVoiding = $invoice->getOriginal('status') === InvoiceStatus::Finalized
                && $invoice->status === InvoiceStatus::Void
                && $invoice->voided_at !== null
                && array_diff(array_keys($invoice->getDirty()), self::VOID_COLUMNS) === [];

            if (! $isVoiding) {
                throw new LogicException('Invoices are immutable; only finalized -> void is allowed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Invoices cannot be deleted; void them instead.'));
    }

    public const INVOICED_PERIOD_MESSAGE = 'A bill was already issued for this period — void it first or pick a later date.';

    /** True when the agreement has a finalized invoice whose period touches [$from, $to] (dates, inclusive). */
    public static function finalizedInPeriod(int $agreementId, string $from, string $to = '9999-12-31'): bool
    {
        return static::where('rental_agreement_id', $agreementId)
            ->where('status', InvoiceStatus::Finalized)
            ->whereDate('period_start', '<=', $to)
            ->whereDate('period_end', '>=', $from)
            ->exists();
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function billingBatch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class)->orderBy('sort');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
