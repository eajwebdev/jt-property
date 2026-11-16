<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** An agreement's share of a utility bill. Frozen once the bill is billed. */
class UtilityAllocation extends Model
{
    protected $fillable = ['utility_bill_id', 'rental_agreement_id', 'amount_cents', 'basis'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    protected static function booted(): void
    {
        $guard = function (self $a) {
            if (UtilityBill::whereKey($a->utility_bill_id)->where('status', 'billed')->exists()) {
                throw new LogicException('Allocations of a billed utility bill cannot be changed.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function utilityBill(): BelongsTo
    {
        return $this->belongsTo(UtilityBill::class);
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class);
    }
}
