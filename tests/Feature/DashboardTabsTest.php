<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabs_load_lazily_and_count_late_dues(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        $property = Property::factory()->create();
        $payer = Tenant::factory()->create(['property_id' => $property->id, 'full_name' => 'Late Larry']);
        $account = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $payer->id]);
        // Due 2026-10-01, 46 days ago on 2026-11-16: one late account in the 31-60 bucket.
        app(ReceivableLedgerService::class)->post($account, LedgerEntryType::Opening, 180000, Carbon::parse('2026-10-01'), $account, 'Opening');
        app(PaymentService::class)->record($account, ['amount_cents' => 50000, 'paid_on' => '2026-11-10', 'method' => 'gcash'], $owner);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('metrics.late_accounts', 1)
            ->missing('income')->missing('late')->missing('occupancy')
            ->reloadOnly('late', fn (Assert $reload) => $reload
                ->where('late.late_accounts', 1)
                ->where('late.late_bills', 1)
                ->where('late.overdue_cents', 130000)
                ->where('late.buckets.31-60.accounts', 1)
                ->where('late.rows.0.tenant', 'Late Larry')
                ->where('late.rows.0.days_late', 46))
            ->reloadOnly('income', fn (Assert $reload) => $reload
                ->has('income.months', 12)
                ->where('income.months.11.month', '2026-11')
                ->where('income.months.11.collected_cents', 50000)
                ->where('income.by_method.0.method', 'gcash'))
            ->reloadOnly('occupancy', fn (Assert $reload) => $reload
                ->where('occupancy.properties.0.id', $property->id)));
    }

    public function test_staff_tabs_only_see_assigned_properties(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $other = Property::factory()->create();
        $account = BillingAccount::factory()->for($other)->create();
        $this->actingAs(User::factory()->owner()->create());
        app(ReceivableLedgerService::class)->post($account, LedgerEntryType::Opening, 50000, Carbon::parse('2026-10-01'), $account, 'Opening');

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('late', fn (Assert $reload) => $reload->where('late.late_accounts', 0)->where('late.rows', []))
            ->reloadOnly('occupancy', fn (Assert $reload) => $reload->where('occupancy.properties', [])));
    }

    public function test_create_and_edit_urls_open_the_modal_forms(): void
    {
        $owner = User::factory()->owner()->create();
        $property = Property::factory()->create();
        $tenant = Tenant::factory()->create(['property_id' => $property->id]);
        $this->actingAs($owner);

        $this->get(route('properties.create'))->assertRedirect(route('properties.index', ['create' => 1]));
        $this->get(route('properties.edit', $property))->assertRedirect(route('properties.show', ['property' => $property, 'edit' => 1]));
        $this->get(route('tenants.create'))->assertRedirect(route('tenants.index', ['create' => 1]));
        $this->get(route('tenants.edit', $tenant))->assertRedirect(route('tenants.show', ['tenant' => $tenant, 'edit' => 1]));

        $this->get(route('properties.index'))->assertInertia(fn (Assert $page) => $page->has('form.types')->where('form.property', null));
        $this->get(route('properties.show', $property))->assertInertia(fn (Assert $page) => $page->where('form.property.id', $property->id));
        $this->get(route('tenants.index'))->assertInertia(fn (Assert $page) => $page->has('form.deliveryMethods')->where('form.tenant', null));
        $this->get(route('tenants.show', $tenant))->assertInertia(fn (Assert $page) => $page->where('form.tenant.id', $tenant->id));
    }
}
