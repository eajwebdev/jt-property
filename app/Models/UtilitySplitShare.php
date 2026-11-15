<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Percent (basis points) or manual (centavos) share of one agreement in a unit's utility. */
class UtilitySplitShare extends Model
{
    protected $fillable = ['unit_utility_id', 'rental_agreement_id', 'percent_bp', 'manual_cents'];

    protected function casts(): array
    {
        return ['percent_bp' => 'integer', 'manual_cents' => 'integer'];
    }

    public function unitUtility(): BelongsTo
    {
        return $this->belongsTo(UnitUtility::class);
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class);
    }
}
