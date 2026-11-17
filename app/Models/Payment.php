<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const METHODS = ['cash', 'bank_transfer', 'gcash', 'maya', 'check', 'other'];

    protected $fillable = [
        'billing_account_id', 'property_id', 'amount_cents', 'paid_on', 'method', 'reference', 'payer_name',
        'collected_by', 'status', 'proof_path', 'notes', 'approved_by', 'ack_number', 'ledger_entry_id',
        'reversed_at', 'reverse_reason', 'receipt_emailed_at', 'receipt_emailed_to',
    ];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_on' => 'date', 'reversed_at' => 'datetime', 'receipt_emailed_at' => 'datetime'];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
