<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_route_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertStatus(404);
    }

    public function test_factory_defaults_to_staff_and_has_owner_state(): void
    {
        $this->assertSame(Role::Staff, User::factory()->create()->role);
        $this->assertTrue(User::factory()->owner()->create()->isOwner());
        $this->assertSame(Role::Accountant, User::factory()->accountant()->create()->role);
    }

    public function test_owner_gate_allows_only_owner(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->owner()->create())->allows('owner'));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('owner'));
        $this->assertFalse(Gate::forUser(User::factory()->accountant()->create())->allows('owner'));
    }

    public function test_role_middleware(): void
    {
        Route::middleware(['web', 'auth', 'role:owner'])->get('/_test/owner-only', fn () => 'ok');

        $this->actingAs(User::factory()->owner()->create())
            ->get('/_test/owner-only')->assertOk()->assertSee('ok');

        $this->actingAs(User::factory()->create())
            ->get('/_test/owner-only')->assertForbidden();

        auth()->logout();
        $this->get('/_test/owner-only')->assertRedirect('/login');
    }
}
