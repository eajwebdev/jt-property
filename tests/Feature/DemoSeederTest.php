<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_is_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Property::count());

        $a101 = Unit::where('code', 'A101')->firstOrFail();
        $this->assertSame(4, $a101->beds()->count());
        $agreements = $a101->agreements()->get();
        $this->assertCount(4, $agreements);
        $this->assertEqualsCanonicalizing([5, 12, 18, 28], $agreements->pluck('due_day')->all());
        $this->assertCount(4, $agreements->map(fn ($a) => $a->rentOn('2026-09-01'))->unique());
        $this->assertNull(Tenant::where('full_name', 'like', 'Cara%')->firstOrFail()->email);

        $b201 = Unit::where('code', 'B201')->firstOrFail();
        $this->assertSame(1, $b201->agreements()->count());
        $this->assertSame(4, $b201->occupancies()->count());
        $this->assertSame(900000, $b201->agreements()->first()->rentOn('2026-09-01'));
        $this->assertSame(0, Unit::where('code', 'B202')->firstOrFail()->agreements()->count());
        $this->assertSame('maintenance', Unit::where('code', 'A103')->firstOrFail()->displayStatus());
        $this->assertSame(0, RentalAgreement::whereNull('unit_id')->count());
    }

    public function test_staff_can_access_riverside_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $staff = User::where('email', 'staff@jt.test')->firstOrFail();

        $this->assertTrue($staff->canAccessProperty(Property::where('code', 'RIV')->firstOrFail()));
        $this->assertFalse($staff->canAccessProperty(Property::where('code', 'HIL')->firstOrFail()));
    }

    public function test_seeding_twice_does_not_duplicate(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Property::count());
        $this->assertSame(6, RentalAgreement::count());
    }
}
