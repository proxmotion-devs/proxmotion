<?php

declare(strict_types=1);

namespace App\Domain\Networking;

use App\Models\IpAddress;
use App\Models\IpPool;

/**
 * Adds addresses to a pool, skipping protected ones (gateway, node IPs…) and
 * addresses that already exist in any pool.
 */
final readonly class AddIpAddresses
{
    public function __construct(private ProtectedAddresses $protected) {}

    /**
     * @param  list<string>  $addresses  normalised addresses inside the pool's network
     * @return array{added: int, existing: int, protected: array<string, string>}
     */
    public function handle(IpPool $pool, array $addresses): array
    {
        $protected = array_intersect_key($this->protected->for($pool), array_flip($addresses));
        $candidates = array_values(array_diff($addresses, array_keys($protected)));

        $existing = [];

        foreach (array_chunk($candidates, 500) as $chunk) {
            array_push($existing, ...IpAddress::query()->whereIn('address', $chunk)->pluck('address')->all());
        }

        $new = array_values(array_diff($candidates, $existing));
        $now = now();

        foreach (array_chunk($new, 500) as $chunk) {
            $pool->addresses()->getQuery()->insertOrIgnore(array_map(fn (string $address) => [
                'ip_pool_id' => $pool->id,
                'address' => $address,
                'is_reserved' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return [
            'added' => count($new),
            'existing' => count($existing),
            'protected' => $protected,
        ];
    }
}
