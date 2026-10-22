<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_defaults_to_english()
    {
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'en')
                ->where('availableLocales', ['en', 'tr']));
    }

    public function test_browser_language_is_used_for_guests()
    {
        $this->withHeader('Accept-Language', 'tr-TR,tr;q=0.9,en;q=0.8')
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'tr'));
    }

    public function test_guests_can_switch_language_for_their_session()
    {
        $this->from(route('login'))
            ->patch(route('locale.update'), ['locale' => 'tr'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('locale', 'tr');

        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'tr'));
    }

    public function test_signed_in_users_keep_their_language()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('locale.update'), ['locale' => 'tr'])
            ->assertSessionHasNoErrors();

        $this->assertSame('tr', $user->refresh()->locale);

        // The user's preference wins over the browser language in a new session.
        $this->flushSession();

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'en')
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'tr'));
    }

    public function test_unsupported_locales_are_rejected()
    {
        $this->patch(route('locale.update'), ['locale' => 'xx'])
            ->assertSessionHasErrors('locale');

        $this->assertNull(session('locale'));
    }

    public function test_backend_messages_are_translated()
    {
        $user = User::factory()->create(['locale' => 'tr']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => '',
            ])
            ->assertSessionHasErrors(['email' => 'E-posta adresi alanı zorunludur.']);
    }
}
