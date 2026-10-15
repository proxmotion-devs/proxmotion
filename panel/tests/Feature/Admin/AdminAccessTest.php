<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_can_open_the_admin_dashboard()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/Dashboard'));
    }

    public function test_clients_cannot_open_the_admin_dashboard()
    {
        $client = User::factory()->create();

        $this->actingAs($client)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login()
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_new_users_are_clients_by_default()
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::Client, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_role_cannot_be_mass_assigned()
    {
        $user = User::factory()->create();

        $user->fill(['role' => UserRole::Admin->value]);

        $this->assertSame(UserRole::Client, $user->role);
    }

    public function test_role_cannot_be_changed_through_the_profile_form()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
            'role' => UserRole::Admin->value,
        ]);

        $this->assertSame(UserRole::Client, $user->refresh()->role);
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
