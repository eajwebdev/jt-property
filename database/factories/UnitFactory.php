<?php

namespace Database\Factories;

use App\Enums\RentMode;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'code' => strtoupper(fake()->unique()->bothify('?###')),
            'building' => null,
            'floor' => null,
            'capacity' => 1,
            'rent_mode' => RentMode::Whole,
            'status' => UnitStatus::Available,
        ];
    }
}
