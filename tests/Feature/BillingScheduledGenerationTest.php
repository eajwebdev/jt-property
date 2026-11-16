<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Settings;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BillingScheduledGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 06:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_scheduler_is_off_by_default_and_repeated_runs_do_not_duplicate_bills(): void
    {
        $this->assertSame(0, Artisan::call('billing:generate-scheduled'));
        $this->assertSame(0, Invoice::count());

        $owner = User::where('role', 'owner')->orderBy('id')->firstOrFail();
        Settings::set('auto_generate', true, $owner);
        $this->assertSame(0, Artisan::call('billing:generate-scheduled'));
        $this->assertSame(6, Invoice::count());

        $this->assertSame(0, Artisan::call('billing:generate-scheduled'));
        $this->assertSame(6, Invoice::count());
        $this->assertSame(6, Invoice::distinct()->count('idempotency_key'));

    }
}
