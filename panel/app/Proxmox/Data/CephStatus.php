<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * The health part of GET /cluster/ceph/status (docs/adr/0047).
 */
final readonly class CephStatus
{
    /**
     * @param  list<array{severity: string, message: string}>  $checks
     */
    public function __construct(
        /** HEALTH_OK, HEALTH_WARN or HEALTH_ERR. */
        public string $health,
        public array $checks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $health = is_array($data['health'] ?? null) ? $data['health'] : [];
        $checks = [];

        foreach (is_array($health['checks'] ?? null) ? $health['checks'] : [] as $code => $check) {
            if (! is_array($check) || ($check['muted'] ?? false)) {
                continue;
            }

            $summary = is_array($check['summary'] ?? null) ? $check['summary'] : [];

            $checks[] = [
                'severity' => (string) ($check['severity'] ?? 'HEALTH_WARN'),
                'message' => (string) ($summary['message'] ?? $code),
            ];
        }

        return new self(strtoupper((string) ($health['status'] ?? 'UNKNOWN')), $checks);
    }
}
