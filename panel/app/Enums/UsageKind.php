<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a usage violation is about (docs/adr/0039): the monthly traffic quota
 * or one of the usage limits of Panel settings.
 */
enum UsageKind: string
{
    case Traffic = 'traffic';
    case Cpu = 'cpu';
    case Memory = 'memory';
    case Disk = 'disk';
    case Network = 'network';

    /**
     * The usage limits, i.e. every kind but traffic.
     *
     * @return list<self>
     */
    public static function limits(): array
    {
        return [self::Cpu, self::Memory, self::Disk, self::Network];
    }

    /**
     * Admin label (English key).
     */
    public function label(): string
    {
        return match ($this) {
            self::Traffic => 'Traffic quota',
            self::Cpu => 'CPU',
            self::Memory => 'Memory',
            self::Disk => 'Disk I/O',
            self::Network => 'Network',
        };
    }

    /**
     * The unit of its values: bytes for traffic, else the unit its limit is set in.
     */
    public function unit(): string
    {
        return match ($this) {
            self::Traffic => 'bytes',
            self::Cpu, self::Memory => 'percent',
            self::Disk => 'MB/s',
            self::Network => 'Mbps',
        };
    }
}
