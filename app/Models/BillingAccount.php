<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingAccount extends Model
{
    use HasFactory;

    protected $fillable = ['property_id', 'payer_tenant_id', 'status'];

    protected function casts(): array
    {
        return ['status' => AccountStatus::class];
    }

    protected static function booted(): void
    {
        static::created(function (BillingAccount $account) {
            $account->forceFill(['account_no' => 'JT-'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'payer_tenant_id');
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(RentalAgreement::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(SecurityDeposit::class);
    }
}
