<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One storage entry of GET /cluster/resources?type=storage.
 */
final readonly class StorageInfo
{
    /**
     * @param  list<string>  $content
     */
    public function __construct(
        public string $node,
        public string $name,
        public string $type,
        public array $content,
        public bool $shared,
        public bool $available,
        public ?int $totalBytes,
        public ?int $usedBytes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $content = array_values(array_filter(explode(',', (string) ($data['content'] ?? ''))));

        return new self(
            node: (string) $data['node'],
            name: (string) $data['storage'],
            type: (string) ($data['plugintype'] ?? 'unknown'),
            content: $content,
            shared: (bool) ($data['shared'] ?? false),
            available: ($data['status'] ?? null) === 'available',
            totalBytes: isset($data['maxdisk']) ? (int) $data['maxdisk'] : null,
            usedBytes: isset($data['disk']) ? (int) $data['disk'] : null,
        );
    }
}
