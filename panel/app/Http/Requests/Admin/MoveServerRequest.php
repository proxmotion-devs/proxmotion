<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\IpPool;
use App\Models\Node;
use App\Models\PlacementGroup;
use App\Models\Server;
use App\Provisioning\Moves\MoveTarget;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a server to another node, cluster or location (docs/adr/0037): a
 * node (optionally its storage) or a placement group, the IPv4 pool of a new
 * location, the confirmation for routed addresses, and the hostname typed
 * again when the server leaves its cluster (its VM is copied and the old one
 * deleted).
 */
class MoveServerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->targetServer();

        return [
            'target' => ['required', Rule::in(['node', 'group'])],
            'node' => ['required_if:target,node', 'nullable', 'string', 'exists:nodes,uuid'],
            'placement_group' => ['required_if:target,group', 'nullable', 'string', 'exists:placement_groups,uuid'],
            'storage' => ['nullable', 'string', 'max:64'],
            'ipv4_pool' => ['nullable', 'string', 'exists:ip_pools,uuid'],
            'routed_confirmed' => ['boolean'],
            'confirm_hostname' => [Rule::requiredIf(fn () => $this->leavesCluster()), 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) use ($server) {
                if ($this->leavesCluster() && (! is_string($value) || strcasecmp(trim($value), $server->hostname) !== 0)) {
                    $fail(__('Type the hostname of the server to confirm.'));
                }
            }],
        ];
    }

    public function toTarget(): MoveTarget
    {
        $byGroup = $this->input('target') === 'group';
        $pool = $this->string('ipv4_pool')->toString();
        $storage = $this->string('storage')->toString();

        return new MoveTarget(
            node: $byGroup ? null : $this->node(),
            group: $byGroup ? $this->group() : null,
            storage: $byGroup || $storage === '' ? null : $storage,
            routedConfirmed: $this->boolean('routed_confirmed'),
            ipv4Pool: $pool === '' ? null : IpPool::query()->where('uuid', $pool)->first(),
        );
    }

    /**
     * Whether the server may leave its cluster: a node of another cluster, or
     * a placement group with nodes of another cluster.
     */
    private function leavesCluster(): bool
    {
        $server = $this->targetServer();

        if ($this->input('target') === 'group') {
            return $this->group()?->nodes()->where('cluster_id', '!=', $server->cluster_id)->exists() ?? false;
        }

        $node = $this->node();

        return $node !== null && $node->cluster_id !== $server->cluster_id;
    }

    private function node(): ?Node
    {
        $uuid = $this->input('node');

        return is_string($uuid) ? Node::query()->with('cluster.location')->where('uuid', $uuid)->first() : null;
    }

    private function group(): ?PlacementGroup
    {
        $uuid = $this->input('placement_group');

        return is_string($uuid) ? PlacementGroup::query()->with('location')->where('uuid', $uuid)->first() : null;
    }

    private function targetServer(): Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : abort(404);
    }
}
