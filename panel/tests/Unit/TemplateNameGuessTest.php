<?php

namespace Tests\Unit;

use App\Domain\Infrastructure\TemplateNameGuess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TemplateNameGuessTest extends TestCase
{
    /**
     * @return array<string, array{string|null, array{name: string, distribution: string, version: string}}>
     */
    public static function names(): array
    {
        return [
            'ubuntu without dot' => ['ubuntu-2404-cloud', ['name' => 'Ubuntu 24.04 LTS', 'distribution' => 'ubuntu', 'version' => '24.04']],
            'ubuntu with dot' => ['Ubuntu-22.04', ['name' => 'Ubuntu 22.04 LTS', 'distribution' => 'ubuntu', 'version' => '22.04']],
            'ubuntu interim' => ['ubuntu-25-10', ['name' => 'Ubuntu 25.10', 'distribution' => 'ubuntu', 'version' => '25.10']],
            'debian' => ['debian-12-generic', ['name' => 'Debian 12', 'distribution' => 'debian', 'version' => '12']],
            'alma short' => ['alma9', ['name' => 'AlmaLinux 9', 'distribution' => 'almalinux', 'version' => '9']],
            'rocky' => ['tpl-rocky-linux-9.5', ['name' => 'Rocky Linux 9.5', 'distribution' => 'rocky', 'version' => '9.5']],
            'arch' => ['archlinux-cloud', ['name' => 'Arch Linux', 'distribution' => 'arch', 'version' => '']],
            'windows server' => ['win2022-std', ['name' => 'Windows Server 2022', 'distribution' => 'windows', 'version' => '2022']],
            'own version numbers' => ['pardus-23-cloud', ['name' => 'Pardus 23', 'distribution' => 'pardus', 'version' => '23']],
            'mint before ubuntu' => ['linuxmint-22-ubuntu', ['name' => 'Linux Mint 22', 'distribution' => 'linuxmint', 'version' => '22']],
            'the versions of its base' => ['oracle-linux-9.4', ['name' => 'Oracle Linux 9.4', 'distribution' => 'ol', 'version' => '9.4']],
            'unknown' => ['my-image', ['name' => 'my-image', 'distribution' => '', 'version' => '']],
            'no name' => [null, ['name' => '', 'distribution' => '', 'version' => '']],
        ];
    }

    /**
     * @param  array{name: string, distribution: string, version: string}  $expected
     */
    #[DataProvider('names')]
    public function test_the_operating_system_is_guessed_from_the_template_name(?string $name, array $expected)
    {
        $this->assertSame($expected, TemplateNameGuess::fromName($name));
    }
}
