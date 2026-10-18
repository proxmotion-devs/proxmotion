<?php

namespace Tests\Unit;

use App\Domain\Infrastructure\TemplateNameGuess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TemplateNameGuessTest extends TestCase
{
    /**
     * @return array<string, array{string|null, array{name: string, family: string, version: string}}>
     */
    public static function names(): array
    {
        return [
            'ubuntu without dot' => ['ubuntu-2404-cloud', ['name' => 'Ubuntu 24.04 LTS', 'family' => 'ubuntu', 'version' => '24.04']],
            'ubuntu with dot' => ['Ubuntu-22.04', ['name' => 'Ubuntu 22.04 LTS', 'family' => 'ubuntu', 'version' => '22.04']],
            'ubuntu interim' => ['ubuntu-25-10', ['name' => 'Ubuntu 25.10', 'family' => 'ubuntu', 'version' => '25.10']],
            'debian' => ['debian-12-generic', ['name' => 'Debian 12', 'family' => 'debian', 'version' => '12']],
            'alma short' => ['alma9', ['name' => 'AlmaLinux 9', 'family' => 'almalinux', 'version' => '9']],
            'rocky' => ['tpl-rocky-linux-9.5', ['name' => 'Rocky Linux 9.5', 'family' => 'rocky', 'version' => '9.5']],
            'windows server' => ['win2022-std', ['name' => 'Windows Server 2022', 'family' => 'windows', 'version' => '2022']],
            'unknown' => ['my-image', ['name' => 'my-image', 'family' => 'other', 'version' => '']],
            'no name' => [null, ['name' => '', 'family' => 'other', 'version' => '']],
        ];
    }

    /**
     * @param  array{name: string, family: string, version: string}  $expected
     */
    #[DataProvider('names')]
    public function test_the_operating_system_is_guessed_from_the_template_name(?string $name, array $expected)
    {
        $this->assertSame($expected, TemplateNameGuess::fromName($name));
    }
}
