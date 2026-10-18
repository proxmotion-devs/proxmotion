<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\InputValidator;
use App\Domain\Apps\Recipe;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * App inputs are checked against their declared types before they are
 * written to inputs.json (docs/adr/0015).
 */
class InputValidatorTest extends TestCase
{
    private function recipe(): Recipe
    {
        return Recipe::fromArray([
            'slug' => 'demo',
            'name' => 'Demo',
            'version' => '1.0.0',
            'category' => 'web',
            'description' => ['en' => 'Demo'],
            'os' => [['family' => 'ubuntu', 'versions' => ['24.04']]],
            'requirements' => ['min_memory_mib' => 512, 'min_disk_mib' => 5120, 'min_cores' => 1],
            'network_models' => ['bridged'],
            'install_modes' => ['post'],
            'billing' => 'free',
            'license' => 'none',
            'inputs' => [
                ['key' => 'port', 'type' => 'port', 'default' => 8080],
                ['key' => 'workers', 'type' => 'integer', 'min' => 1, 'max' => 8, 'required' => false],
                ['key' => 'mode', 'type' => 'enum', 'options' => ['fast', 'safe'], 'default' => 'safe'],
                ['key' => 'name', 'type' => 'slug'],
                ['key' => 'email', 'type' => 'email', 'required' => false],
                ['key' => 'domain', 'type' => 'domain', 'required' => false],
                ['key' => 'password', 'type' => 'password', 'required' => false],
                ['key' => 'title', 'type' => 'string', 'max_length' => 20, 'required' => false],
                ['key' => 'tls', 'type' => 'boolean', 'default' => true],
                ['key' => 'download', 'type' => 'url', 'required' => false],
            ],
            'timeout_minutes' => 5,
        ]);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, string|int|bool>
     */
    private function validate(array $inputs): array
    {
        return (new InputValidator)->validate($this->recipe(), $inputs);
    }

    public function test_valid_inputs_are_cast_and_defaults_filled_in()
    {
        $this->assertSame(
            ['port' => 9000, 'workers' => 4, 'mode' => 'safe', 'name' => 'office-vpn', 'domain' => 'vpn.example.com', 'tls' => false, 'download' => 'https://downloads.example.com/app.zip?token=abc'],
            $this->validate(['port' => '9000', 'workers' => 4, 'name' => 'office-vpn', 'domain' => 'vpn.example.com', 'tls' => false, 'download' => 'https://downloads.example.com/app.zip?token=abc']),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'missing required input' => [[], 'name'],
            'port out of range' => [['name' => 'a', 'port' => 0], 'port'],
            'integer above max' => [['name' => 'a', 'workers' => 9], 'workers'],
            'unknown enum value' => [['name' => 'a', 'mode' => 'turbo'], 'mode'],
            'slug with shell characters' => [['name' => 'a; reboot'], 'name'],
            'invalid email' => [['name' => 'a', 'email' => 'nobody'], 'email'],
            'invalid domain' => [['name' => 'a', 'domain' => '-bad-.example.com'], 'domain'],
            'short password' => [['name' => 'a', 'password' => 'short'], 'password'],
            'string with a line break' => [['name' => 'a', 'title' => "two\nlines"], 'title'],
            'string too long' => [['name' => 'a', 'title' => str_repeat('x', 21)], 'title'],
            'not a boolean' => [['name' => 'a', 'tls' => 'maybe'], 'tls'],
            'plain HTTP address' => [['name' => 'a', 'download' => 'http://downloads.example.com/app.zip'], 'download'],
            'not an address' => [['name' => 'a', 'download' => 'file:///etc/passwd'], 'download'],
            'array instead of a value' => [['name' => ['a']], 'name'],
            'undeclared input' => [['name' => 'a', 'script' => 'curl x | sh'], 'script'],
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_are_refused(array $inputs, string $key)
    {
        try {
            $this->validate($inputs);
            $this->fail('The inputs were accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inputs.'.$key, $e->errors());
        }
    }
}
