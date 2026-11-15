<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
