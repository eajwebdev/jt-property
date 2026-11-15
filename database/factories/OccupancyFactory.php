<?php

namespace Database\Factories;

use App\Models\Occupancy;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Occupancy>
 */
class OccupancyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'unit_id' => Unit::factory(),
            'bed_id' => null,
            'rental_agreement_id' => null,
            'is_payer' => false,
            'move_in_date' => '2026-01-01',
            'move_out_date' => null,
        ];
    }
}
