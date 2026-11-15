<?php

namespace App\Models;

use App\Enums\RentMode;
use App\Enums\UnitStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = ['property_id', 'code', 'building', 'floor', 'capacity', 'rent_mode', 'status', 'description', 'amenities'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'rent_mode' => RentMode::class,
            'status' => UnitStatus::class,
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(RentalAgreement::class);
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    /** Number of occupancies active on the given date (move-out date is inclusive: the tenant still occupies on it). */
    public function occupiedCountOn($date): int
    {
        $date = Carbon::parse($date)->toDateString();

        return $this->occupancies()
            ->where('move_in_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('move_out_date')->orWhere('move_out_date', '>=', $date))
            ->count();
    }

    /** One of: maintenance, unavailable, full, vacant, available. */
    public function displayStatus($date = null): string
    {
        if ($this->status !== UnitStatus::Available) {
            return $this->status->value;
        }

        $occupied = $this->occupiedCountOn($date ?? today());

        return match (true) {
            $occupied >= $this->capacity => 'full',
            $occupied === 0 => 'vacant',
            default => 'available',
        };
    }
}
