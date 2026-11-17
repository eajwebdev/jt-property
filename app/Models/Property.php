<?php

namespace App\Models;

use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'type', 'address', 'dti_registration_no', 'business_permit_no', 'description', 'amenities', 'auto_send_email', 'archived_at'];

    protected function casts(): array
    {
        return [
            'type' => PropertyType::class,
            'auto_send_email' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function billingAccounts(): HasMany
    {
        return $this->hasMany(BillingAccount::class);
    }

    /** Staff assigned to this property. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
