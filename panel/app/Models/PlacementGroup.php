<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Database\Factories\PlacementGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A named set of nodes of one location, and optionally their storages, that
 * packages and new servers target; placement still chooses inside it.
 * Panel only: nothing of it exists on Proxmox (docs/adr/0020).
 *
 * @property int $id
 * @property string $uuid
 * @property int $location_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Node> $nodes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Storage> $storages
 */
#[Fillable(['location_id', 'name', 'description'])]
class PlacementGroup extends Model
{
    /** @use HasFactory<PlacementGroupFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsToMany<Node, $this>
     */
    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class);
    }

    /**
     * The storages disks may go to; none means each node's storages marked "use for servers".
     *
     * @return BelongsToMany<Storage, $this>
     */
    public function storages(): BelongsToMany
    {
        return $this->belongsToMany(Storage::class);
    }

    /**
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class);
    }

    /**
     * Whether the group may place a disk on this storage of one of its nodes
     * (storages and nodes must be loaded).
     */
    public function allowsStorage(Storage $storage): bool
    {
        if (! $this->nodes->contains('id', $storage->node_id)) {
            return false;
        }

        return $this->storages->isEmpty()
            ? $storage->use_for_servers
            : $this->storages->contains('id', $storage->id);
    }

    /**
     * @param  Collection<int, PlacementGroup>  $groups
     * @return list<int>
     */
    public static function nodeIdsOf(Collection $groups): array
    {
        return array_values($groups->flatMap(fn (PlacementGroup $group) => $group->nodes->map(fn (Node $node) => $node->id))->unique()->all());
    }
}
