<?php

namespace Tests\Feature\Admin;

use App\Domain\Settings\Branding;
use App\Domain\Settings\PanelSettings;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Notifications\AdminAlert;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Panel settings > Branding (docs/adr/0053): name, colour, logos, favicon and
 * support contacts, shown on every page, the customer console and in mail.
 */
class BrandingTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function upload(string $image, UploadedFile $file): TestResponse
    {
        return $this->post(route('admin.settings.branding.store', $image), ['file' => $file]);
    }

    public function test_without_settings_the_panel_is_called_app_name_and_looks_like_proxmotion()
    {
        config(['app.name' => 'Proxmotion']);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('/favicon.svg', false)
            ->assertDontSee('--primary:', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('name', 'Proxmotion')
                ->where('branding.color', null)
                ->where('branding.logo_url', null)
                ->where('branding.favicon_url', null)
                ->where('branding.support_email', null));
    }

    public function test_the_name_and_colour_reach_every_page()
    {
        PanelSettings::save(['brand_name' => 'Example Cloud', 'brand_color' => '#facc15']);

        $this->get(route('admin.dashboard'))
            // Until the page's own title replaces it.
            ->assertSee('<title>Example Cloud</title>', false)
            ->assertSee('--primary: #facc15;', false)
            // Black reads better on yellow.
            ->assertSee('--primary-foreground: #000000;', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('name', 'Example Cloud')
                ->where('branding.color', '#facc15')
                ->where('branding.color_foreground', '#000000'));
    }

    public function test_an_uploaded_logo_is_served_and_replaced()
    {
        $this->upload('logo', UploadedFile::fake()->image('logo.png', 256, 256))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $first = PanelSettings::get('brand_logo');
        $this->assertMatchesRegularExpression('/^'.Branding::FILE_PATTERN.'$/', $first);
        Storage::disk('local')->assertExists('branding/'.$first);

        $this->assertSame(['image' => 'logo'], AuditLog::query()->where('action', 'branding.image_uploaded')->sole()->properties);

        $url = route('branding.image', $first);
        $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->where('branding.logo_url', $url));

        // Served to everyone, the sign-in page and the customer console included.
        auth()->logout();
        $response = $this->get($url)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        // Publicly cached: never a session or XSRF cookie.
        $this->assertSame([], $response->headers->getCookies());

        // A new upload gets a new address; the old file is deleted.
        $this->actingAs($this->admin);
        $this->upload('logo', UploadedFile::fake()->image('logo.webp', 128, 64))->assertSessionHasNoErrors();

        $second = PanelSettings::get('brand_logo');
        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('.webp', $second);
        Storage::disk('local')->assertMissing('branding/'.$first);
        $this->get($url)->assertNotFound();
    }

    public function test_defaults_from_env_are_checked_like_the_form()
    {
        config([
            'proxmotion.branding.color' => '#2563EB',
            'proxmotion.branding.support_email' => 'not an address',
            'proxmotion.branding.support_url' => 'javascript:alert(1)',
        ]);

        $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('branding.color', '#2563eb')
            ->where('branding.support_email', null)
            ->where('branding.support_url', null));

        config(['proxmotion.branding.support_url' => 'http://help.example.com', 'proxmotion.branding.support_email' => 'help@example.com']);

        $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('branding.support_email', 'help@example.com')
            ->where('branding.support_url', null));
    }

    public function test_metadata_is_removed_from_uploads()
    {
        $exif = "Exif\0\0GPS 52.5200 13.4050";
        // Kept in a variable: the fake's file is deleted with it.
        $fake = UploadedFile::fake()->image('logo.jpg', 64, 64);
        $jpeg = (string) file_get_contents($fake->getRealPath());
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

        $this->upload('logo', UploadedFile::fake()->createWithContent('logo.jpg', $jpeg))->assertSessionHasNoErrors();

        $stored = (string) Storage::disk('local')->get('branding/'.PanelSettings::get('brand_logo'));
        $this->assertStringNotContainsString('GPS', $stored);
        $this->assertSame([64, 64], array_slice((array) getimagesizefromstring($stored), 0, 2));
    }

    public function test_a_failed_write_keeps_the_current_image()
    {
        $this->upload('logo', UploadedFile::fake()->image('logo.png', 64, 64));
        $current = PanelSettings::get('brand_logo');

        $disk = \Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set('local', $disk);

        $this->upload('logo', UploadedFile::fake()->image('logo.png', 64, 64))
            ->assertSessionHasErrors(['file' => 'The image could not be stored. Check that storage/ is writable.']);

        $this->assertSame($current, PanelSettings::get('brand_logo'));
        Storage::disk('local')->assertExists('branding/'.$current);
        $this->assertSame(1, AuditLog::query()->where('action', 'branding.image_uploaded')->count());
    }

    public function test_only_the_images_in_use_are_served()
    {
        $stray = str_repeat('a', 40).'.png';
        Storage::disk('local')->put('branding/'.$stray, 'not in use');

        $this->get(route('branding.image', $stray))->assertNotFound();
        $this->get('/branding/..%2F..%2F.env')->assertNotFound();
    }

    public function test_svg_and_other_files_are_refused()
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        // A PNG name does not help: the type is read from the content.
        $html = UploadedFile::fake()->createWithContent('logo.png', '<html><script>alert(1)</script></html>');

        $this->upload('logo', $svg)->assertSessionHasErrors('file');
        $this->upload('logo', $html)->assertSessionHasErrors('file');
        $this->upload('logo', UploadedFile::fake()->image('tiny.png', 8, 8))->assertSessionHasErrors('file');
        $this->upload('logo', UploadedFile::fake()->image('big.png', 256, 256)->size(2000))->assertSessionHasErrors('file');

        $this->assertNull(PanelSettings::get('brand_logo'));
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_favicon_must_be_square_and_replaces_the_default_icons()
    {
        $this->upload('favicon', UploadedFile::fake()->image('icon.png', 64, 32))->assertSessionHasErrors('file');
        $this->upload('favicon', UploadedFile::fake()->image('icon.png', 1024, 1024))->assertSessionHasErrors('file');

        $this->upload('favicon', UploadedFile::fake()->image('icon.png', 64, 64))->assertSessionHasNoErrors();

        $this->get(route('admin.dashboard'))
            ->assertSee(route('branding.image', PanelSettings::get('brand_favicon')), false)
            ->assertDontSee('/favicon.svg', false);
    }

    public function test_an_image_can_be_removed()
    {
        $this->upload('logo_dark', UploadedFile::fake()->image('dark.png', 64, 64));
        $file = PanelSettings::get('brand_logo_dark');

        $this->delete(route('admin.settings.branding.destroy', 'logo_dark'))->assertRedirect();

        $this->assertNull(PanelSettings::get('brand_logo_dark'));
        Storage::disk('local')->assertMissing('branding/'.$file);
        $this->assertSame(['image' => 'logo_dark'], AuditLog::query()->where('action', 'branding.image_removed')->sole()->properties);
    }

    public function test_unknown_images_and_guests_are_refused()
    {
        $this->upload('banner', UploadedFile::fake()->image('banner.png', 64, 64))->assertNotFound();

        auth()->logout();
        $this->upload('logo', UploadedFile::fake()->image('logo.png', 64, 64))->assertRedirect(route('login'));
        $this->delete(route('admin.settings.branding.destroy', 'logo'))->assertRedirect(route('login'));

        $this->assertNull(PanelSettings::get('brand_logo'));
    }

    public function test_the_sign_in_page_and_the_customer_console_carry_the_branding()
    {
        PanelSettings::save(['brand_name' => 'Example Cloud', 'support_email' => 'support@example.com']);
        auth()->logout();

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('name', 'Example Cloud')
            ->where('branding.support_email', 'support@example.com'));

        $server = Server::factory()->create();
        $this->get(route('console.show', $server))->assertInertia(fn (Assert $page) => $page
            ->component('console/Show')
            ->where('branding.name', 'Example Cloud')
            ->where('branding.support_email', 'support@example.com'));
    }

    public function test_mail_carries_the_name()
    {
        PanelSettings::save(['brand_name' => 'Example Cloud']);

        $mail = (new AdminAlert(null, 'Test email', lines: [['This is a test.']]))->toMail(User::factory()->make());

        $this->assertSame('[Example Cloud] Test email', $mail->subject);
        $html = (string) $mail->render();
        $this->assertStringContainsString('Example Cloud', $html);
        $this->assertStringNotContainsString('Laravel', $html);
    }
}
