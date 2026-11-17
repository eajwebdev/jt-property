<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_production_without_seed_password_is_refused(): void
    {
        $this->app['env'] = 'production';
        config(['app.seed_password' => null]);

        try {
            (new DatabaseSeeder)->run();
            $this->fail('Seeder should refuse to run in production without SEED_PASSWORD.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SEED_PASSWORD', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'owner@jt']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Staff',
            'email' => 'new.staff@jt.test',
            'role' => 'staff',
            'password' => 'Str0ng-Pass-123',
            'password_confirmation' => 'Str0ng-Pass-123',
        ], $overrides);
    }

    public function test_owner_sees_users_page(): void
    {
        User::factory()->create(['email' => 'listed@jt.test']);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/settings/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/users')
                ->has('users', 2)
                ->has('users.0', fn (Assert $u) => $u->hasAll(['id', 'name', 'email', 'role', 'deactivated_at', 'created_at'])));
    }

    public function test_staff_and_accountant_cannot_see_users_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/users')->assertForbidden();
        $this->actingAs(User::factory()->accountant()->create())->get('/settings/users')->assertForbidden();
    }

    public function test_owner_can_create_user_with_hashed_password(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post('/settings/users', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $user = User::where('email', 'new.staff@jt.test')->firstOrFail();
        $this->assertSame(Role::Staff, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Str0ng-Pass-123', $user->password));
    }

    public function test_staff_cannot_create_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/settings/users', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new.staff@jt.test']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'new.staff@jt.test']);

        $this->actingAs(User::factory()->owner()->create())
            ->post('/settings/users', $this->payload())
            ->assertSessionHasErrors('email');
    }

    public function test_invalid_role_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)
            ->post('/settings/users', $this->payload(['role' => 'superadmin']))
            ->assertSessionHasErrors('role');

        $this->post('/settings/users', $this->payload(['role' => 'accountant']))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'new.staff@jt.test']);
    }

    public function test_seeded_owner_can_log_in(): void
    {
        $this->seed();

        $this->post('/login', ['email' => 'owner@jt', 'password' => config('app.seed_password') ?: 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->isOwner());
    }

    public function test_production_seed_creates_only_the_owner_and_allows_login(): void
    {
        $this->app['env'] = 'production';
        config(['app.seed_password' => 'Production-Seed-Password-123']);

        (new DatabaseSeeder)->run();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'owner@jt', 'role' => 'owner']);
        $this->post('/login', ['email' => 'owner@jt', 'password' => 'Production-Seed-Password-123'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs(User::where('email', 'owner@jt')->firstOrFail());
    }

    public function test_seeded_staff_can_log_in_and_only_two_demo_accounts_exist(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseMissing('users', ['email' => 'accountant@jt.test']);
        $this->post('/login', ['email' => User::where('role', 'staff')->orderBy('id')->value('email'), 'password' => config('app.seed_password') ?: 'password']);

        $this->assertAuthenticatedAs(User::where('role', 'staff')->orderBy('id')->firstOrFail());
        $this->get('/dashboard')->assertOk();
    }

    public function test_reseeding_restores_demo_password_without_changing_role(): void
    {
        $this->seed();
        // The seeded owner, remembered by id: this test changes its role, so it cannot be found by role afterwards.
        $id = User::where('role', 'owner')->orderBy('id')->value('id');
        User::findOrFail($id)->forceFill(['password' => 'Changed-Pass-1', 'role' => Role::Staff])->save();

        $this->seed();

        $owner = User::findOrFail($id);
        $this->assertTrue(Hash::check(config('app.seed_password') ?: 'password', $owner->password));
        $this->assertSame(Role::Staff, $owner->role);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Your account has been deactivated.');

        $this->assertGuest();
    }

    public function test_owner_can_update_name_and_role(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->create();

        $this->actingAs($owner)
            ->patch("/settings/users/{$staff->id}", ['name' => 'Renamed', 'role' => 'owner'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $staff->refresh();
        $this->assertSame('Renamed', $staff->name);
        $this->assertSame(Role::Owner, $staff->role);
    }

    public function test_owner_cannot_assign_accountant_role_to_existing_user(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->create();

        $this->actingAs($owner)
            ->patch("/settings/users/{$staff->id}", ['name' => $staff->name, 'role' => 'accountant'])
            ->assertSessionHasErrors('role');

        $this->assertSame(Role::Staff, $staff->fresh()->role);
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->patch("/settings/users/{$owner->id}", ['name' => $owner->name, 'role' => 'staff'])
            ->assertSessionHasErrors('role');

        $this->assertSame(Role::Owner, $owner->fresh()->role);
    }

    public function test_owner_can_be_demoted_when_another_active_owner_exists(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->actingAs($owner)
            ->patch("/settings/users/{$owner->id}", ['name' => $owner->name, 'role' => 'staff'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Staff, $owner->fresh()->role);
    }

    public function test_deactivated_owner_does_not_count_as_another_owner(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create(['deactivated_at' => now()]);

        $this->actingAs($owner)
            ->patch("/settings/users/{$owner->id}", ['name' => $owner->name, 'role' => 'staff'])
            ->assertSessionHasErrors('role');
    }

    public function test_owner_can_deactivate_and_reactivate_staff(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->create();

        $this->actingAs($owner)->patch("/settings/users/{$staff->id}/deactivate")
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($staff->fresh()->isActive());

        $this->actingAs($owner)->patch("/settings/users/{$staff->id}/reactivate")
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($staff->fresh()->isActive());
    }

    public function test_owner_cannot_deactivate_themselves(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->actingAs($owner)->patch("/settings/users/{$owner->id}/deactivate")
            ->assertSessionHasErrors('user');

        $this->assertTrue($owner->fresh()->isActive());
    }

    public function test_last_active_owner_cannot_be_deactivated(): void
    {
        $owner = User::factory()->owner()->create();
        $actor = User::factory()->owner()->create();
        $actor->forceFill(['deactivated_at' => null])->save();
        // Make $owner the only *other* owner: deactivate guard counts active owners excluding target.
        $this->actingAs($actor)->patch("/settings/users/{$owner->id}/deactivate")->assertSessionHasNoErrors();

        // Now $actor is the only active owner and cannot be deactivated (self or otherwise).
        $this->actingAs($actor)->patch("/settings/users/{$actor->id}/deactivate")->assertSessionHasErrors('user');
        $this->assertTrue($actor->fresh()->isActive());
    }

    public function test_staff_cannot_update_deactivate_or_reactivate_users(): void
    {
        $staff = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($staff)->patch("/settings/users/{$other->id}", ['name' => 'X', 'role' => 'owner'])->assertForbidden();
        $this->actingAs($staff)->patch("/settings/users/{$other->id}/deactivate")->assertForbidden();
        $this->actingAs($staff)->patch("/settings/users/{$other->id}/reactivate")->assertForbidden();
        $this->actingAs($staff)->patch("/settings/users/{$staff->id}", ['name' => 'X', 'role' => 'owner'])->assertForbidden();

        $this->assertSame(Role::Staff, $staff->fresh()->role);
        $this->assertTrue($other->fresh()->isActive());
    }

    public function test_shared_user_props_exclude_unneeded_fields(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('auth.user.email')
                ->missing('auth.user.deactivated_at'));
    }

    public function test_flash_success_is_shared(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->withSession(['success' => 'Saved.'])
            ->get('/settings/users')
            ->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Saved.'));
    }
}
