<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Occupancy extends Model
{
    use HasFactory;

    protected $table = 'occupancies';

    protected $fillable = ['tenant_id', 'unit_id', 'bed_id', 'rental_agreement_id', 'is_payer', 'move_in_date', 'move_out_date'];

    protected function casts(): array
    {
        return [
            'is_payer' => 'boolean',
            'move_in_date' => 'date',
            'move_out_date' => 'date',
        ];
    }

    /** Occupancies in effect on the date (move-out date is inclusive). */
    public function scopeActiveOn(Builder $query, $date): Builder
    {
        $date = Carbon::parse($date)->toDateString();

        return $query->where('move_in_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('move_out_date')->orWhere('move_out_date', '>=', $date));
    }

    /** Occupancies that are active today or start in the future. */
    public function scopeCurrentOrFuture(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('move_out_date')->orWhere('move_out_date', '>=', today()->toDateString()));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class, 'rental_agreement_id');
    }
}
