<?php

namespace Database\Factories;

use App\Enums\DeliveryMethod;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'full_name' => 'Demo '.fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => '09'.fake()->numerify('#########'),
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
            'preferred_delivery' => DeliveryMethod::Email,
            'email_opt_in' => true,
        ];
    }
}
