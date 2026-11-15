<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneTimeCharge extends Model
{
    protected $fillable = [
        'billing_account_id', 'rental_agreement_id', 'charge_category_id', 'label', 'kind', 'amount_cents',
        'bill_month', 'invoice_id', 'created_by', 'reason',
    ];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'bill_month' => 'date'];
    }

    /** What this adds to the bill: positive for a charge, negative for a credit. */
    public function getSignedCentsAttribute(): int
    {
        return $this->kind === 'credit' ? -$this->amount_cents : $this->amount_cents;
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChargeCategory::class, 'charge_category_id');
    }
}
