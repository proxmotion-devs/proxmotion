<?php

namespace Tests\Unit;

use App\Domain\Infrastructure\TemplateNameGuess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TemplateNameGuessTest extends TestCase
{
    /**
     * @return array<string, array{string|null, array{name: string, distribution: string, version: string, compatible_with: string|null, compatible_version: string|null}}>
     */
    public static function names(): array
    {
        return [
            'ubuntu without dot' => ['ubuntu-2404-cloud', ['name' => 'Ubuntu 24.04 LTS', 'distribution' => 'ubuntu', 'version' => '24.04', 'compatible_with' => null, 'compatible_version' => null]],
            'ubuntu with dot' => ['Ubuntu-22.04', ['name' => 'Ubuntu 22.04 LTS', 'distribution' => 'ubuntu', 'version' => '22.04', 'compatible_with' => null, 'compatible_version' => null]],
            'ubuntu interim' => ['ubuntu-25-10', ['name' => 'Ubuntu 25.10', 'distribution' => 'ubuntu', 'version' => '25.10', 'compatible_with' => null, 'compatible_version' => null]],
            'debian' => ['debian-12-generic', ['name' => 'Debian 12', 'distribution' => 'debian', 'version' => '12', 'compatible_with' => null, 'compatible_version' => null]],
            'alma short' => ['alma9', ['name' => 'AlmaLinux 9', 'distribution' => 'almalinux', 'version' => '9', 'compatible_with' => null, 'compatible_version' => null]],
            'rocky' => ['tpl-rocky-linux-9.5', ['name' => 'Rocky Linux 9.5', 'distribution' => 'rocky', 'version' => '9.5', 'compatible_with' => null, 'compatible_version' => null]],
            'arch' => ['archlinux-cloud', ['name' => 'Arch Linux', 'distribution' => 'arch', 'version' => '', 'compatible_with' => null, 'compatible_version' => null]],
            'windows server' => ['win2022-std', ['name' => 'Windows Server 2022', 'distribution' => 'windows', 'version' => '2022', 'compatible_with' => null, 'compatible_version' => null]],
            'own version numbers' => ['pardus-23-cloud', ['name' => 'Pardus 23', 'distribution' => 'pardus', 'version' => '23', 'compatible_with' => 'debian', 'compatible_version' => null]],
            'mint before ubuntu' => ['linuxmint-22-ubuntu', ['name' => 'Linux Mint 22', 'distribution' => 'linuxmint', 'version' => '22', 'compatible_with' => 'ubuntu', 'compatible_version' => null]],
            'the versions of its base' => ['oracle-linux-9.4', ['name' => 'Oracle Linux 9.4', 'distribution' => 'ol', 'version' => '9.4', 'compatible_with' => 'almalinux', 'compatible_version' => '9.4']],
            'unknown' => ['my-image', ['name' => 'my-image', 'distribution' => '', 'version' => '', 'compatible_with' => null, 'compatible_version' => null]],
            'no name' => [null, ['name' => '', 'distribution' => '', 'version' => '', 'compatible_with' => null, 'compatible_version' => null]],
        ];
    }

    /**
     * @param  array{name: string, distribution: string, version: string, compatible_with: string|null, compatible_version: string|null}  $expected
     */
    #[DataProvider('names')]
    public function test_the_operating_system_is_guessed_from_the_template_name(?string $name, array $expected)
    {
        $this->assertSame($expected, TemplateNameGuess::fromName($name));
    }
}
