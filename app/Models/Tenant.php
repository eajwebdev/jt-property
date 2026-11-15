<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'full_name', 'email', 'mobile', 'emergency_contact_name', 'emergency_contact_phone',
        'preferred_delivery', 'email_opt_in', 'remarks', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'preferred_delivery' => DeliveryMethod::class,
            'email_opt_in' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    /** Billing accounts this tenant pays for. */
    public function billingAccounts(): HasMany
    {
        return $this->hasMany(BillingAccount::class, 'payer_tenant_id');
    }
}
