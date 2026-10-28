<?php

namespace Tests\Feature\Servers;

use App\Domain\Servers\PasswordGenerator;
use App\Domain\Settings\PanelSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $format
     */
    private function save(array $format): void
    {
        PanelSettings::save($format + [
            'password_length' => 20,
            'password_characters' => 'mixed',
            'password_prefix' => null,
            'password_suffix' => null,
        ]);
    }

    public function test_by_default_it_makes_twenty_mixed_characters()
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', (new PasswordGenerator)->generate());
    }

    public function test_it_follows_the_saved_format()
    {
        $this->save(['password_length' => 16, 'password_characters' => 'lower', 'password_suffix' => '.example!']);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}\.example!$/', (new PasswordGenerator)->generate());
    }

    public function test_a_prefix_surrounds_the_random_part_of_symbols()
    {
        $this->save(['password_length' => 12, 'password_characters' => 'symbols', 'password_prefix' => 'ACME-']);

        $this->assertMatchesRegularExpression('/^ACME-[A-Za-z0-9!@#%+=.\-_]{12}$/', (new PasswordGenerator)->generate());
    }

    public function test_the_random_part_holds_every_kind_of_its_set()
    {
        $this->save(['password_length' => 12, 'password_characters' => 'lower']);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $password = (new PasswordGenerator)->generate();

            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
        }

        $this->save(['password_length' => 12, 'password_characters' => 'symbols']);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $this->assertMatchesRegularExpression('/[!@#%+=.\-_]/', (new PasswordGenerator)->generate());
        }
    }

    public function test_the_random_part_starts_with_a_letter_so_it_fits_cloud_init()
    {
        foreach (['lower', 'mixed', 'symbols'] as $characters) {
            $this->save(['password_length' => 12, 'password_characters' => $characters]);

            for ($attempt = 0; $attempt < 50; $attempt++) {
                $password = (new PasswordGenerator)->generate();

                $this->assertMatchesRegularExpression('/^[A-Za-z]/', $password);
                $this->assertTrue(PasswordGenerator::fitsCloudInit($password), $password);
            }
        }
    }

    public function test_a_temporary_password_always_fits_cloud_init_and_windows()
    {
        $this->save(['password_characters' => 'lower', 'password_prefix' => '@acme:']);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $password = (new PasswordGenerator)->temporary();

            $this->assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9]{23}$/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
        }
    }

    public function test_fits_cloud_init_refuses_what_yaml_would_not_read_back()
    {
        foreach (['a-very-long-password', 'Abc!@#%+=.-_x', 'Pass#word', 'x\'quoted"'] as $password) {
            $this->assertTrue(PasswordGenerator::fitsCloudInit($password), $password);
        }

        // Cannot start a value, a tag, a comment, a quote, a number; ": " ends it, spaces and line breaks.
        foreach (['@secret-pass', '%secret-pass', '`secret-pass', '!secret-pass', '#secret-pass', '\'secret-pass', '1234567890123', 'pass: word', 'password:', 'pass word', "pass\nruncmd", 'pässword-long', ''] as $password) {
            $this->assertFalse(PasswordGenerator::fitsCloudInit($password), $password);
        }
    }

    public function test_broken_env_values_fall_back_to_a_usable_format()
    {
        PanelSettings::save([]);
        config(['proxmotion.passwords.characters' => 'Mixed', 'proxmotion.passwords.length' => 0]);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', (new PasswordGenerator)->generate());
    }

    public function test_kinds_counts_the_random_part_and_the_affixes()
    {
        $this->assertSame(2, PasswordGenerator::kinds('lower'));
        $this->assertSame(3, PasswordGenerator::kinds('lower', '', '.example!'));
        $this->assertSame(3, PasswordGenerator::kinds('lower', 'ACME', ''));
        $this->assertSame(3, PasswordGenerator::kinds('mixed'));
        $this->assertSame(4, PasswordGenerator::kinds('symbols'));
    }
}
