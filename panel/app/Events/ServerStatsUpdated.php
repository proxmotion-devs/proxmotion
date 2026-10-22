<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Usage\LiveStats;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * New live figures of one server, for the administrators watching its page (docs/adr/0036).
 * Broadcast at once: a queued figure would be old by the time it is sent.
 *
 * @phpstan-import-type Stats from LiveStats
 */
final class ServerStatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  Stats  $stats
     */
    public function __construct(
        public readonly string $serverUuid,
        public readonly array $stats,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("admin.servers.{$this->serverUuid}");
    }

    public function broadcastAs(): string
    {
        return 'stats.updated';
    }

    /**
     * @return Stats
     */
    public function broadcastWith(): array
    {
        return $this->stats;
    }
}
