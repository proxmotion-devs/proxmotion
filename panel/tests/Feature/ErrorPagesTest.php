<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/broken', fn () => throw new RuntimeException('Broken.'));
        Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException);
    }

    public function test_a_missing_record_shows_the_panels_page_with_its_props(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->get('/admin/servers/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/Show')
                ->where('status', 404)
                ->where('auth.user.email', fn (string $email) => $email !== '')
                ->has('name'));
    }

    public function test_an_unknown_address_shows_it_without_a_session(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/Show')
                ->where('status', 404)
                ->where('auth.user', null)
                ->has('locale'));
    }

    public function test_a_server_error_shows_it_unless_debugging(): void
    {
        config(['app.debug' => false]);

        $this->get('/_test/broken')
            ->assertInternalServerError()
            ->assertInertia(fn (Assert $page) => $page->component('errors/Show')->where('status', 500));

        config(['app.debug' => true]);

        $this->get('/_test/broken')
            ->assertInternalServerError()
            ->assertSee('Broken.');
    }

    public function test_an_expired_page_goes_back_with_a_toast(): void
    {
        $this->from('/admin')
            ->post('/_test/expired')
            ->assertRedirect('/admin')
            ->assertSessionHas('inertia.flash_data.toast.message', 'The page expired. Please try again.');
    }

    public function test_the_api_keeps_its_json_errors(): void
    {
        $this->getJson('/api/v1/servers/00000000-0000-0000-0000-000000000000')
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);

        $this->get('/api/v1/no-such-endpoint')->assertNotFound()->assertHeaderMissing('X-Inertia');
    }
}
