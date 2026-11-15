<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class RentalAgreement extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_account_id', 'unit_id', 'bed_id', 'due_day', 'cycle_start_day', 'issue_lead_days',
        'prorate', 'start_date', 'end_date', 'status', 'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'due_day' => 'integer',
            'cycle_start_day' => 'integer',
            'issue_lead_days' => 'integer',
            'prorate' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => AgreementStatus::class,
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function rentRates(): HasMany
    {
        return $this->hasMany(RentRate::class);
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    public function isWholeUnit(): bool
    {
        return $this->bed_id === null;
    }

    /** Rent in centavos from the latest rate effective on or before $date, or null if none. */
    public function rentOn(CarbonInterface|string $date): ?int
    {
        return $this->rentRates()
            ->where('effective_from', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('effective_from')
            ->value('rent_cents');
    }
}
