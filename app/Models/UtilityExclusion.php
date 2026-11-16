<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The owner chose not to bill this utility to this agreement for this month. */
class UtilityExclusion extends Model
{
    protected $fillable = ['rental_agreement_id', 'type', 'bill_month', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['bill_month' => 'date'];
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class);
    }
}
