<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_appearance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/appearance', ['appearance' => 'dark'])
            ->assertRedirect();

        $this->assertSame('dark', $user->fresh()->appearance);
    }

    public function test_invalid_appearance_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/appearance', ['appearance' => 'neon'])
            ->assertSessionHasErrors('appearance');

        $this->assertSame('system', $user->fresh()->appearance);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->patch('/settings/appearance', ['appearance' => 'dark'])->assertRedirect('/login');
    }

    public function test_appearance_is_shared_with_the_frontend(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.appearance', 'system'));
    }
}
