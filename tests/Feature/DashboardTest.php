<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_home_sends_guests_to_the_login_page()
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_home_sends_signed_in_users_to_the_dashboard()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/dashboard')->assertOk();
    }

    public function test_monthly_guide_flowchart_is_shown_to_signed_in_users_only()
    {
        $this->get('/guide')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());

        $this->get('/guide')->assertOk()->assertInertia(fn ($page) => $page->component('guide'));
    }
}
