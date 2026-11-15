<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class InvoiceLineItem extends Model
{
    protected $fillable = [
        'invoice_id', 'sort', 'category', 'label', 'description',
        'qty_e3', 'unit_rate_e4', 'amount_cents', 'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'qty_e3' => 'integer',
            'unit_rate_e4' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice lines are append-only.'));
        static::deleting(fn () => throw new LogicException('Invoice lines are append-only.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
