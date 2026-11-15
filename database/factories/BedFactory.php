<?php

namespace Database\Factories;

use App\Models\Bed;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bed>
 */
class BedFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'label' => 'Bed '.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
