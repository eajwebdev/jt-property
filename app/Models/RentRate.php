<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RentRate extends Model
{
    use HasFactory;

    protected $fillable = ['rental_agreement_id', 'rent_cents', 'effective_from', 'reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'rent_cents' => 'integer',
            'effective_from' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rent rates are append-only.'));
        static::deleting(fn () => throw new LogicException('Rent rates are append-only.'));
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class, 'rental_agreement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
