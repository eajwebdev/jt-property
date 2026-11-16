<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingBatch extends Model
{
    protected $fillable = ['year', 'month', 'property_id', 'created_by', 'status', 'counts'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'counts' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingBatchItem::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
