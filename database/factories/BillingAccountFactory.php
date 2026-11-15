<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingAccount>
 */
class BillingAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'payer_tenant_id' => fn (array $attributes) => Tenant::factory()->create(['property_id' => $attributes['property_id']])->id,
            'status' => AccountStatus::Active,
        ];
    }
}
