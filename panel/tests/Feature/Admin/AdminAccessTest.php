<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_can_open_the_admin_dashboard()
    {
        $admin = User::factory()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/Dashboard'));
    }

    public function test_admins_without_two_factor_are_sent_to_security_settings()
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_admins_with_unconfirmed_two_factor_are_sent_to_security_settings()
    {
        $admin = User::factory()->withTwoFactor()->create([
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_two_factor_requirement_can_be_disabled_for_local_development()
    {
        config(['proxmotion.require_admin_two_factor' => false]);

        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_guests_are_redirected_to_login()
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_public_registration_is_disabled()
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
