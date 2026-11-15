<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'action' => 'property.created',
            'subject_type' => Property::class,
            'subject_id' => Property::factory(),
            'properties' => [],
            'ip_address' => null,
        ];
    }
}
