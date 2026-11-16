<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalance extends Model
{
    protected $fillable = [
        'billing_account_id', 'amount_cents', 'as_of', 'description', 'breakdown', 'notes',
        'status', 'created_by', 'approved_by', 'ledger_entry_id', 'reversed_at', 'reverse_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'as_of' => 'date',
            'breakdown' => 'array',
            'reversed_at' => 'datetime',
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
