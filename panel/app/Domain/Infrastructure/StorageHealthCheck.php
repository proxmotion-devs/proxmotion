<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Domain\Settings\PanelSettings;
use App\Enums\StorageHealth;
use App\Models\Storage;

/**
 * Judges the health of a storage or a physical disk from what the sync read
 * (docs/adr/0047). Problems are English keys with their placeholders, so the
 * panel and mails show them in the reader's language.
 *
 * @phpstan-import-type HealthProblem from Storage
 */
final readonly class StorageHealthCheck
{
    /** zpool states that still serve data, with less redundancy. */
    private const array ZFS_DEGRADED = ['DEGRADED'];

    /** Per cent points a level stays below its threshold once reached, so values around it do not flap. */
    public const int HYSTERESIS_POINTS = 2;

    public function __construct(
        private int $warningPercent,
        private int $criticalPercent,
    ) {}

    public static function fromSettings(): self
    {
        return new self(
            (int) PanelSettings::get('storage_warning_percent'),
            (int) PanelSettings::get('storage_critical_percent'),
        );
    }

    /**
     * @param  string|null  $zfsState  the state of its zpool, when it is a ZFS storage
     * @param  string|null  $cephHealth  the Ceph cluster's health, when it is a Ceph storage Proxmox manages
     * @param  StorageHealth  $previous  its health at the previous sync, for the hysteresis of the thresholds
     * @return array{0: StorageHealth, 1: list<HealthProblem>}
     */
    public function storage(
        bool $available,
        ?int $usedBytes,
        ?int $totalBytes,
        ?string $zfsState = null,
        ?int $metadataUsedBytes = null,
        ?int $metadataTotalBytes = null,
        ?string $cephHealth = null,
        StorageHealth $previous = StorageHealth::Ok,
    ): array {
        $problems = [];

        if (! $available) {
            $problems[] = self::problem(StorageHealth::Critical, 'Proxmox reports this storage as not available.');
        }

        if (($percent = self::percent($usedBytes, $totalBytes)) !== null && ($health = $this->level($percent, $previous)) !== StorageHealth::Ok) {
            $problems[] = self::problem($health, ':percent% of its space is used.', ['percent' => $percent]);
        }

        if ($zfsState !== null && $zfsState !== 'ONLINE') {
            $health = in_array($zfsState, self::ZFS_DEGRADED, true) ? StorageHealth::Warning : StorageHealth::Critical;
            $problems[] = self::problem($health, 'Its ZFS pool is :state.', ['state' => $zfsState]);
        }

        if (($percent = self::percent($metadataUsedBytes, $metadataTotalBytes)) !== null && ($health = $this->level($percent, $previous)) !== StorageHealth::Ok) {
            // A thin pool whose metadata is full can no longer write, whatever space is left.
            $problems[] = self::problem($health, ':percent% of its thin pool metadata is used.', ['percent' => $percent]);
        }

        if ($cephHealth !== null && ($health = self::ceph($cephHealth)) !== StorageHealth::Ok) {
            $problems[] = self::problem($health, 'Ceph reports :status.', ['status' => $cephHealth]);
        }

        return [self::worstOf($problems), $problems];
    }

    /**
     * @param  string|null  $smart  smartctl's overall result
     * @param  int|null  $wearout  rated life left in per cent
     * @return array{0: StorageHealth, 1: list<HealthProblem>}
     */
    public function disk(?string $smart, ?int $wearout, bool $present = true, StorageHealth $previous = StorageHealth::Ok): array
    {
        $problems = [];

        if (! $present) {
            $problems[] = self::problem(StorageHealth::Warning, 'Proxmox no longer reports this disk.');
        }

        if ($smart === 'FAILED') {
            $problems[] = self::problem(StorageHealth::Critical, 'SMART reports the disk as failing.');
        }

        if ($wearout !== null && ($health = $this->level(100 - $wearout, $previous)) !== StorageHealth::Ok) {
            $problems[] = self::problem($health, ':percent% of its rated life is used.', ['percent' => 100 - $wearout]);
        }

        // RAID controllers and virtual disks give no SMART result.
        $known = $present && in_array($smart, ['PASSED', 'OK', 'FAILED'], true);

        return [self::worstOf($problems, $known ? StorageHealth::Ok : StorageHealth::Unknown), $problems];
    }

    public static function ceph(?string $health): StorageHealth
    {
        return match ($health) {
            'HEALTH_OK' => StorageHealth::Ok,
            'HEALTH_WARN' => StorageHealth::Warning,
            'HEALTH_ERR' => StorageHealth::Critical,
            default => StorageHealth::Unknown,
        };
    }

    /**
     * A level already reached is kept until the value drops HYSTERESIS_POINTS below its threshold.
     */
    private function level(int $percent, StorageHealth $previous): StorageHealth
    {
        $critical = $this->criticalPercent - ($previous === StorageHealth::Critical ? self::HYSTERESIS_POINTS : 0);
        $warning = $this->warningPercent - ($previous->rank() >= StorageHealth::Warning->rank() ? self::HYSTERESIS_POINTS : 0);

        return match (true) {
            $percent >= $critical => StorageHealth::Critical,
            $percent >= $warning => StorageHealth::Warning,
            default => StorageHealth::Ok,
        };
    }

    private static function percent(?int $used, ?int $total): ?int
    {
        return $used !== null && $total !== null && $total > 0 ? (int) floor($used * 100 / $total) : null;
    }

    /**
     * @param  array<string, string|int>  $replace
     * @return HealthProblem
     */
    private static function problem(StorageHealth $severity, string $message, array $replace = []): array
    {
        return ['severity' => $severity->value, 'message' => $message, 'replace' => $replace];
    }

    /**
     * @param  list<HealthProblem>  $problems
     */
    private static function worstOf(array $problems, StorageHealth $default = StorageHealth::Ok): StorageHealth
    {
        return StorageHealth::worst(
            array_map(fn (array $problem) => StorageHealth::from($problem['severity']), $problems),
            $default,
        );
    }
}
