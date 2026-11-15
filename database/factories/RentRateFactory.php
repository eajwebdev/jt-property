<?php

namespace Database\Factories;

use App\Models\RentalAgreement;
use App\Models\RentRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RentRate>
 */
class RentRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rental_agreement_id' => RentalAgreement::factory(),
            'rent_cents' => fake()->numberBetween(30, 300) * 10000,
            'effective_from' => '2026-01-01',
            'reason' => null,
            'created_by' => null,
        ];
    }
}
