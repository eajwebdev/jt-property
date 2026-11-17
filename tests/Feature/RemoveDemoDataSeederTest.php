<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\OpeningBalance;
use App\Models\Property;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RemoveDemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RemoveDemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_only_the_original_demo_fixtures(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertEqualsCanonicalizing(['RIV', 'HIL'], Property::pluck('code')->all());

        $this->seed(RemoveDemoDataSeeder::class);
        $this->seed(RemoveDemoDataSeeder::class);

        $this->assertSame(0, Property::count());
        $this->assertSame(0, BillingAccount::count());
        $this->assertSame(0, OpeningBalance::count());
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(['owner@jt'], User::pluck('email')->all());
    }

    public function test_it_refuses_to_remove_a_changed_property(): void
    {
        $this->seed(DatabaseSeeder::class);
        Property::where('code', 'HIL')->update(['name' => 'Real Apartments']);

        try {
            $this->seed(RemoveDemoDataSeeder::class);
            $this->fail('Changed property data must not be removed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('sample properties have changed', $exception->getMessage());
        }

        $this->assertSame(2, Property::count());
    }
}
