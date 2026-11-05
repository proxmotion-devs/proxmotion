<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Domain\Networking\ProtectedAddresses;
use App\Enums\NodeStatus;
use App\Enums\ServerMoveStatus;
use App\Enums\ServerStatus;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Node;
use App\Models\ServerMove;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a node Proxmox no longer reports from the panel (docs/adr/0057).
 * Nothing is changed on Proxmox. The node is soft-deleted: terminated servers
 * and past moves keep pointing at it, and the sync restores it when Proxmox
 * reports it again.
 */
final readonly class NodeRemover
{
    public function __construct(private ProtectedAddresses $protected) {}

    /**
     * @throws NodeNotRemovable
     */
    public function remove(Node $node, User $admin, ?string $ipAddress = null): void
    {
        if ($node->status !== NodeStatus::Unknown) {
            throw new NodeNotRemovable('Proxmox still reports this node. Remove it from the cluster in Proxmox first.');
        }

        $servers = $node->servers()->where('status', '!=', ServerStatus::Terminated)->count();

        if ($servers > 0) {
            throw new NodeNotRemovable(':count servers are still on this node. Move or terminate them first.', ['count' => (string) $servers]);
        }

        if ($this->hasOpenMoves($node)) {
            throw new NodeNotRemovable('A move from or to this node is not finished yet.');
        }

        DB::transaction(function () use ($node, $admin, $ipAddress) {
            $addresses = $this->nodeAddresses($node);

            AuditLog::record('node.removed', $admin, $node, [
                'name' => $node->name,
                'cluster' => $node->cluster->name,
                'addresses' => $addresses,
            ], $ipAddress);

            $node->delete();

            // Its addresses were reserved by the sync so no server gets them; once no
            // other node of the location has them, they are free again.
            $released = array_values(array_diff($addresses, $this->protected->nodeAddresses($node->cluster->location_id)));

            if ($released !== []) {
                IpAddress::query()
                    ->whereIn('address', $released)
                    ->whereNull('server_id')
                    ->where('is_reserved', true)
                    ->where('notes', 'Proxmox node')
                    ->update(['is_reserved' => false, 'notes' => null]);
            }
        });
    }

    /**
     * Moves still running from or to the node, or ones Proxmox finished that a
     * new move task still has to finish (docs/adr/0037 §7).
     */
    private function hasOpenMoves(Node $node): bool
    {
        return ServerMove::query()
            ->where(fn ($query) => $query->where('source_node_id', $node->id)->orWhere('target_node_id', $node->id))
            ->where(fn ($query) => $query
                ->whereIn('status', [ServerMoveStatus::Queued, ServerMoveStatus::Running])
                ->orWhere(fn ($query) => $query->where('status', ServerMoveStatus::Failed)->whereNotNull('migrated_at')->whereNull('recorded_at')))
            ->exists();
    }

    /**
     * The addresses on the node's bridges, as the sync reserved them.
     *
     * @return list<string>
     */
    private function nodeAddresses(Node $node): array
    {
        $addresses = [];

        foreach ($node->bridges()->whereNotNull('cidr')->pluck('cidr') as $cidr) {
            $ip = explode('/', (string) $cidr)[0];

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = (string) inet_ntop((string) inet_pton($ip));
            }
        }

        return array_values(array_unique($addresses));
    }
}
