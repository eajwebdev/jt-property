<?php

namespace Tests\Feature\Settings;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class BillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'company_name' => 'JT Rentals',
            'company_address' => '1 Rizal St',
            'company_phone' => '0917 000 0000',
            'company_email' => 'hello@jt.test',
            'payment_instructions' => [
                ['method' => 'gcash', 'label' => 'GCash', 'account_name' => 'Juan T', 'account_number' => '0917', 'notes' => ''],
            ],
            'statement_notes' => 'Pay on time',
            'auto_send_email' => true,
            'auto_generate' => false,
            'generate_lead_days' => 5,
            'reminder_days_before' => 2,
            'reminder_on_due' => false,
            'reminder_days_after' => 4,
            'late_fee_policy' => 'fixed',
            'late_fee_value' => '1,500.50',
            'late_fee_grace_days' => 2,
        ], $o);
    }

    public function test_defaults_are_returned_when_unset(): void
    {
        $this->assertSame('JT Property Management', Settings::get('company_name'));
        $this->assertFalse(Settings::get('auto_send_email'));
        $this->assertFalse(Settings::get('auto_generate'));
        $this->assertSame(7, Settings::get('generate_lead_days'));
        $this->assertSame(3, Settings::get('reminder_days_before'));
        $this->assertTrue(Settings::get('reminder_on_due'));
        $this->assertSame(3, Settings::get('reminder_days_after'));
        $this->assertSame('none', Settings::get('late_fee_policy'));
        $this->assertSame([], Settings::get('payment_instructions'));
        $this->assertSame(0, Settings::get('late_fee_grace_days'));
        $this->assertSame('fallback', Settings::get('nope', 'fallback'));
        $this->assertCount(15, Settings::all());
    }

    public function test_set_persists_clears_cache_and_audits_old_and_new(): void
    {
        $owner = $this->user(Role::Owner);
        $this->actingAs($owner);

        Settings::get('company_name');
        Settings::set('company_name', 'Acme', $owner);

        $this->assertSame('Acme', Settings::get('company_name'));
        $this->assertDatabaseHas('app_settings', ['key' => 'company_name', 'updated_by' => $owner->id]);
        $log = ActivityLog::where('action', 'settings.updated')->firstOrFail();
        $this->assertSame(['key' => 'company_name', 'old' => 'JT Property Management', 'new' => 'Acme'], $log->properties);
    }

    public function test_set_rejects_unknown_key(): void
    {
        $owner = $this->user(Role::Owner);
        $this->expectException(InvalidArgumentException::class);
        Settings::set('mystery', 1, $owner);
    }

    public function test_owner_saves_all_settings_and_values_are_stored_in_centavos(): void
    {
        $owner = $this->user(Role::Owner);

        $this->actingAs($owner)->put('/settings/billing', $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('JT Rentals', Settings::get('company_name'));
        $this->assertTrue(Settings::get('auto_send_email'));
        $this->assertSame(150050, Settings::get('late_fee_value'));
        $this->assertSame('gcash', Settings::get('payment_instructions')[0]['method']);
    }

    public function test_percent_policy_is_stored_as_basis_points(): void
    {
        $owner = $this->user(Role::Owner);

        $this->actingAs($owner)->put('/settings/billing', $this->payload(['late_fee_policy' => 'percent', 'late_fee_value' => '2.5']))->assertSessionHasNoErrors();

        $this->assertSame(250, Settings::get('late_fee_value'));
    }

    public function test_unchanged_values_are_not_audited(): void
    {
        $owner = $this->user(Role::Owner);
        $this->actingAs($owner)->put('/settings/billing', $this->payload());
        $count = ActivityLog::where('action', 'settings.updated')->count();
        $this->actingAs($owner)->put('/settings/billing', $this->payload());

        $this->assertSame($count, ActivityLog::where('action', 'settings.updated')->count());
    }

    public function test_staff_and_accountant_cannot_write(): void
    {
        foreach ([Role::Staff, Role::Accountant] as $role) {
            $this->actingAs($this->user($role))->put('/settings/billing', $this->payload())->assertForbidden();
        }
        $this->assertSame('JT Property Management', Settings::get('company_name'));
    }

    public function test_everyone_sees_company_and_payment_instructions_but_only_owner_gets_automation(): void
    {
        $this->actingAs($this->user(Role::Staff))->get('/settings/billing')->assertInertia(fn (Assert $p) => $p
            ->component('settings/billing')
            ->where('canEdit', false)
            ->has('settings.company_name')
            ->has('settings.payment_instructions')
            ->missing('settings.auto_send_email')
            ->missing('settings.late_fee_policy'));

        $this->actingAs($this->user(Role::Owner))->get('/settings/billing')->assertInertia(fn (Assert $p) => $p
            ->where('canEdit', true)
            ->has('settings.auto_send_email')
            ->has('settings.late_fee_policy'));
    }

    public function test_company_name_is_shared_with_every_page(): void
    {
        Settings::set('company_name', 'Acme', $this->user(Role::Owner));

        $this->actingAs($this->user(Role::Staff))->get('/settings/billing')->assertInertia(fn (Assert $p) => $p->where('app.company', 'Acme'));
    }

    public function test_every_key_is_validated(): void
    {
        $owner = $this->user(Role::Owner);
        $bad = [
            'company_name' => '',
            'company_email' => 'not-an-email',
            'auto_send_email' => 'maybe',
            'auto_generate' => 'maybe',
            'generate_lead_days' => 32,
            'reminder_days_before' => -1,
            'reminder_on_due' => 'maybe',
            'reminder_days_after' => 61,
            'late_fee_policy' => 'huge',
            'late_fee_grace_days' => -1,
        ];

        $res = $this->actingAs($owner)->put('/settings/billing', $this->payload($bad + ['payment_instructions' => [['method' => 'cheque', 'label' => '', 'account_name' => 'x', 'account_number' => 'y']]]));
        $res->assertSessionHasErrors(array_merge(array_keys($bad), ['payment_instructions.0.method', 'payment_instructions.0.label']));

        $this->actingAs($owner)->put('/settings/billing', $this->payload(['late_fee_policy' => 'percent', 'late_fee_value' => '100.01']))->assertSessionHasErrors('late_fee_value');
        $this->actingAs($owner)->put('/settings/billing', $this->payload(['late_fee_policy' => 'fixed', 'late_fee_value' => 'abc']))->assertSessionHasErrors('late_fee_value');
    }

    public function test_unknown_keys_are_rejected(): void
    {
        $this->actingAs($this->user(Role::Owner))->put('/settings/billing', $this->payload(['sneaky' => 1]))->assertSessionHasErrors('sneaky');
    }
}
