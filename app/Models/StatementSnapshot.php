<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** The issued statement PDF and the numbers it showed. Immutable once written. */
class StatementSnapshot extends Model
{
    protected $fillable = ['invoice_id', 'path', 'sha256', 'totals', 'generated_at', 'generated_by'];

    protected function casts(): array
    {
        return ['totals' => 'array', 'generated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Statement snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Statement snapshots are immutable.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
