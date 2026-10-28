<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One storage of GET /storage: where its data lives (docs/adr/0047).
 */
final readonly class StorageConfig
{
    public function __construct(
        public string $name,
        public string $type,
        /** zfspool: the dataset (e.g. rpool/data); rbd: the Ceph pool. */
        public ?string $pool,
        public ?string $volumeGroup,
        public ?string $thinPool,
        /** Set for Ceph storages of a Ceph cluster Proxmox does not manage. */
        public ?string $monitorHosts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $text = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return new self(
            name: (string) $data['storage'],
            type: (string) ($data['type'] ?? 'unknown'),
            pool: $text($data['pool'] ?? null),
            volumeGroup: $text($data['vgname'] ?? null),
            thinPool: $text($data['thinpool'] ?? null),
            monitorHosts: $text($data['monhost'] ?? null),
        );
    }

    /**
     * The zpool a ZFS storage's dataset lives in.
     */
    public function zpool(): ?string
    {
        return $this->type === 'zfspool' && $this->pool !== null ? explode('/', $this->pool)[0] : null;
    }

    /**
     * A Ceph storage of the Ceph cluster Proxmox manages itself (pveceph).
     */
    public function usesManagedCeph(): bool
    {
        return in_array($this->type, ['rbd', 'cephfs'], true) && $this->monitorHosts === null;
    }
}
