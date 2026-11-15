<?php

namespace Database\Factories;

use App\Enums\PropertyType;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Demo Residences',
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'type' => fake()->randomElement(PropertyType::cases()),
            'address' => 'Demo Address, '.fake()->streetAddress().', '.fake()->city(),
            'description' => null,
            'amenities' => null,
        ];
    }
}
