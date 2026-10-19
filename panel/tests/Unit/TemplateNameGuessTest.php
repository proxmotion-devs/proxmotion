<?php

namespace Tests\Unit;

use App\Domain\Infrastructure\TemplateNameGuess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TemplateNameGuessTest extends TestCase
{
    /**
     * @return array<string, array{string|null, array{name: string, family: string, version: string, distribution: string|null, distribution_version: string|null}}>
     */
    public static function names(): array
    {
        return [
            'ubuntu without dot' => ['ubuntu-2404-cloud', ['name' => 'Ubuntu 24.04 LTS', 'family' => 'ubuntu', 'version' => '24.04', 'distribution' => null, 'distribution_version' => null]],
            'ubuntu with dot' => ['Ubuntu-22.04', ['name' => 'Ubuntu 22.04 LTS', 'family' => 'ubuntu', 'version' => '22.04', 'distribution' => null, 'distribution_version' => null]],
            'ubuntu interim' => ['ubuntu-25-10', ['name' => 'Ubuntu 25.10', 'family' => 'ubuntu', 'version' => '25.10', 'distribution' => null, 'distribution_version' => null]],
            'debian' => ['debian-12-generic', ['name' => 'Debian 12', 'family' => 'debian', 'version' => '12', 'distribution' => null, 'distribution_version' => null]],
            'alma short' => ['alma9', ['name' => 'AlmaLinux 9', 'family' => 'almalinux', 'version' => '9', 'distribution' => null, 'distribution_version' => null]],
            'rocky' => ['tpl-rocky-linux-9.5', ['name' => 'Rocky Linux 9.5', 'family' => 'rocky', 'version' => '9.5', 'distribution' => null, 'distribution_version' => null]],
            'windows server' => ['win2022-std', ['name' => 'Windows Server 2022', 'family' => 'windows', 'version' => '2022', 'distribution' => null, 'distribution_version' => null]],
            'derivative with its own versions' => ['pardus-23-cloud', ['name' => 'Pardus 23', 'family' => 'debian', 'version' => '', 'distribution' => 'pardus', 'distribution_version' => '23']],
            'mint before ubuntu' => ['linuxmint-22-ubuntu', ['name' => 'Linux Mint 22', 'family' => 'ubuntu', 'version' => '', 'distribution' => 'linuxmint', 'distribution_version' => '22']],
            'derivative with the base version' => ['oracle-linux-9.4', ['name' => 'Oracle Linux 9.4', 'family' => 'almalinux', 'version' => '9.4', 'distribution' => 'ol', 'distribution_version' => '9.4']],
            'unknown' => ['my-image', ['name' => 'my-image', 'family' => 'other', 'version' => '', 'distribution' => null, 'distribution_version' => null]],
            'no name' => [null, ['name' => '', 'family' => 'other', 'version' => '', 'distribution' => null, 'distribution_version' => null]],
        ];
    }

    /**
     * @param  array{name: string, family: string, version: string, distribution: string|null, distribution_version: string|null}  $expected
     */
    #[DataProvider('names')]
    public function test_the_operating_system_is_guessed_from_the_template_name(?string $name, array $expected)
    {
        $this->assertSame($expected, TemplateNameGuess::fromName($name));
    }
}
