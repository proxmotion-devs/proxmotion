<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\ServerMoveStatus;
use App\Models\Server;
use App\Proxmox\Data\VmResource;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Database\Eloquent\Builder;

/**
 * What Proxmox says about an active server's VM right now, for the admin
 * server page: its power state, and whether it is still where the panel
 * recorded it. Nothing is changed; drift stays the sync's to record (ADR 0005).
 */
final readonly class ServerLiveState
{
    /** On the node the panel recorded (or Proxmox could not be asked). */
    public const string PRESENT = 'present';

    /** Proxmox has no such VM anywhere in the cluster: deleted outside the panel. */
    public const string MISSING = 'missing';

    /** On another node of the cluster: migrated outside the panel. */
    public const string ELSEWHERE = 'elsewhere';

    /** Gone from its node while a move of the panel's own has not been recorded (docs/adr/0037). */
    public const string MOVING = 'moving';

    public function __construct(
        private ServerPower $power,
        private ProxmoxClientFactory $clients,
    ) {}

    /**
     * @return array{power: string|null, vm: string, node: string|null} node: where Proxmox has the VM when ELSEWHERE
     */
    public function read(Server $server): array
    {
        $power = $this->power->liveState($server);

        if ($power !== ServerPower::MISSING) {
            // QemuResource::status() says "unknown" when Proxmox left the field out: as good as not knowing.
            return ['power' => $power === 'unknown' ? null : $power, 'vm' => self::PRESENT, 'node' => null];
        }

        if ($this->moveUnrecorded($server)) {
            return ['power' => null, 'vm' => self::MOVING, 'node' => null];
        }

        try {
            // Pages load the cluster with only a few columns; the connection needs all of them.
            $guests = $this->clients->forCluster($server->cluster()->firstOrFail())->cluster()->guests();
        } catch (ProxmoxException) {
            return ['power' => null, 'vm' => self::PRESENT, 'node' => null];
        }

        $vm = collect($guests)->first(fn (VmResource $guest) => $guest->type === 'qemu' && ! $guest->template && $guest->vmid === $server->vmid);

        return $vm === null
            ? ['power' => null, 'vm' => self::MISSING, 'node' => null]
            : ['power' => null, 'vm' => self::ELSEWHERE, 'node' => $vm->node];
    }

    /**
     * A move still running, or one that failed after Proxmox moved the VM
     * (Finish the move), leaves the server recorded where the VM was.
     */
    private function moveUnrecorded(Server $server): bool
    {
        return $server->moves()
            ->whereNull('recorded_at')
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [ServerMoveStatus::Queued, ServerMoveStatus::Running])
                ->orWhere(fn (Builder $failed) => $failed->where('status', ServerMoveStatus::Failed)->whereNotNull('migrated_at')))
            ->exists();
    }
}
