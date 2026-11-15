<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** One master bill or meter reading for a unit and month. Once billed it never changes. */
class UtilityBill extends Model
{
    protected $fillable = [
        'unit_id', 'type', 'bill_month', 'previous_reading_e3', 'current_reading_e3', 'reading_date', 'rate_e4',
        'base_charge_cents', 'amount_cents', 'evidence_path', 'status', 'entered_by',
    ];

    protected function casts(): array
    {
        return [
            'bill_month' => 'date',
            'reading_date' => 'date',
            'previous_reading_e3' => 'integer',
            'current_reading_e3' => 'integer',
            'rate_e4' => 'integer',
            'base_charge_cents' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $b) => $b->getOriginal('status') === 'billed' ? throw new LogicException('A billed utility bill cannot be changed.') : null);
        static::deleting(fn (self $b) => $b->status === 'billed' ? throw new LogicException('A billed utility bill cannot be deleted.') : null);
    }

    public function isBilled(): bool
    {
        return $this->status === 'billed';
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(UtilityAllocation::class);
    }
}
