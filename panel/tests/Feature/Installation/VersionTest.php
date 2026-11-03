<?php

namespace Tests\Feature\Installation;

use Tests\TestCase;

/**
 * The panel's version (docs/adr/0054).
 */
class VersionTest extends TestCase
{
    public function test_the_version_comes_from_the_version_file_and_is_shown_by_about()
    {
        $version = trim((string) file_get_contents(base_path('VERSION')));

        // The release workflow tags v<version>; images get <version> and <major>.<minor>.
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version);
        $this->assertSame($version, config('proxmotion.version'));

        $this->artisan('about', ['--only' => 'proxmotion'])
            ->expectsOutputToContain($version)
            ->assertSuccessful();
    }
}
