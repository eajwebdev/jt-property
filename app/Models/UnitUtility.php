<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** How one unit pays for one utility type: inclusive, fixed, metered or shared. */
class UnitUtility extends Model
{
    protected $fillable = ['unit_id', 'type', 'mode', 'fixed_cents', 'rate_e4', 'base_charge_cents', 'split_method'];

    protected function casts(): array
    {
        return ['fixed_cents' => 'integer', 'rate_e4' => 'integer', 'base_charge_cents' => 'integer'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(UtilitySplitShare::class);
    }
}
