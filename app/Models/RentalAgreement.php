<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RentalAgreement extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_account_id', 'unit_id', 'bed_id', 'due_day', 'cycle_start_day', 'issue_lead_days',
        'prorate', 'start_date', 'end_date', 'status', 'override_reason', 'replaces_agreement_id',
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

    public function recurringCharges(): HasMany
    {
        return $this->hasMany(RecurringCharge::class);
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    /** Agreements running on the date: start <= date <= end (inclusive, open end = forever). Never trust status alone. */
    public function scopeActiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $date = Carbon::parse($date)->toDateString();

        return $query->where('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }

    /** Agreements still running today or starting later (end date inclusive). Use instead of the status column. */
    public function scopeCurrentOrFuture(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()->toDateString()));
    }

    /** The agreement a transfer created to replace this one (explicit link, never guessed from dates). */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_agreement_id');
    }

    /** True when a transfer replaced this agreement; changes belong on the new one. */
    public function isReplaced(): bool
    {
        return $this->relationLoaded('replacedBy') ? $this->replacedBy !== null : $this->replacedBy()->exists();
    }

    public function isWholeUnit(): bool
    {
        return $this->bed_id === null;
    }

    /** Rent in centavos from the latest rate effective on or before $date, or null if none. */
    public function rentOn(CarbonInterface|string $date): ?int
    {
        return static::currentRents(collect([$this]), $date)[$this->id] ?? null;
    }

    /**
     * The one "rent in force" rule, for many agreements in a single query: the latest rate with
     * effective_from <= $date. Agreements with no such rate are left out.
     *
     * @param  Collection<int, RentalAgreement>  $agreements
     * @return array<int, int> agreement id => rent in centavos
     */
    public static function currentRents(Collection $agreements, CarbonInterface|string $date): array
    {
        return RentRate::query()
            ->whereIn('rental_agreement_id', $agreements->pluck('id')->unique())
            ->where('effective_from', '<=', Carbon::parse($date)->toDateString())
            ->orderBy('effective_from')
            ->get(['rental_agreement_id', 'rent_cents'])
            ->mapWithKeys(fn ($r) => [$r->rental_agreement_id => $r->rent_cents])
            ->all();
    }
}
