<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * On the server, "php artisan migrate:fresh --seed --force" alone loads everything: the owner login, the real
 * Mubarak Boarding House data (October 2026), without sample properties.
 */
class ProductionSeedingTest extends TestCase
{
    use RefreshDatabase;

    /** What "php artisan migrate:fresh --seed --force" runs on the server. */
    private function seedLikeTheServer(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    }

    private function asProduction(?string $password): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.seed_password' => $password]);
    }

    public function test_one_seed_command_loads_the_owner_and_the_real_data_only(): void
    {
        $this->asProduction('Server-Secret-123');

        $this->seedLikeTheServer();

        $owner = User::where('email', 'owner@jt')->sole();
        $this->assertSame('Jun Trio', $owner->name);
        $this->assertTrue(password_verify('Server-Secret-123', $owner->password));
        $this->assertSame(0, User::where('role', 'staff')->count(), 'no demo staff login on a real server');

        $this->assertSame(['MBH'], Property::pluck('code')->all());
        // The real data is loaded first, so its accounts get the first numbers (JT-000001 on a fresh server).
        $this->assertSame('MBH', BillingAccount::orderBy('id')->firstOrFail()->property->code);

        config(['laravel-pdf.driver' => 'browsershot', 'laravel-pdf.browsershot.node_binary' => '/usr/bin/no-such-node']);
        $this->actingAs($owner)
            ->get(route('reports.statement-of-accounts', ['as_of' => '2026-10-05']))
            ->assertOk()
            ->assertSee('ACABAL, Praise Rodriguez')
            ->assertSee('8,333.55')
            ->assertSee('107,432.81')
            ->assertDontSee('Demo Apartments');
    }

    public function test_seeding_again_adds_nothing_twice(): void
    {
        $this->asProduction('Server-Secret-123');
        $this->seedLikeTheServer();
        $counts = [User::count(), Property::count(), BillingAccount::count()];

        $this->seedLikeTheServer();

        $this->assertSame($counts, [User::count(), Property::count(), BillingAccount::count()]);
    }

    public function test_the_server_needs_a_seed_password(): void
    {
        $this->asProduction(null);

        $this->expectException(RuntimeException::class);
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->run();
    }
}
