<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

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
                ->has('users.0', fn (Assert $u) => $u->hasAll(['id', 'name', 'email', 'role', 'created_at'])));
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
        $this->actingAs(User::factory()->owner()->create())
            ->post('/settings/users', $this->payload(['role' => 'superadmin']))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'new.staff@jt.test']);
    }

    public function test_seeded_owner_can_log_in(): void
    {
        $this->seed();

        $this->post('/login', ['email' => 'owner@jt.test', 'password' => 'password']);

        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->isOwner());
    }
}
