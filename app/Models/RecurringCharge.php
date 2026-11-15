<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringCharge extends Model
{
    protected $fillable = ['rental_agreement_id', 'charge_category_id', 'label', 'amount_cents', 'frequency', 'start_date', 'end_date', 'active'];

    protected $attributes = ['frequency' => 'monthly', 'active' => true];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'active' => 'boolean',
        ];
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
