<?php

namespace App\Models;

use App\Enums\BatchItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingBatchItem extends Model
{
    protected $fillable = ['billing_batch_id', 'rental_agreement_id', 'status', 'invoice_id', 'message'];

    protected function casts(): array
    {
        return ['status' => BatchItemStatus::class];
    }

    public function billingBatch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class);
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
