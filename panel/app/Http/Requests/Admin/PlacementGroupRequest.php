<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Location;
use App\Models\Node;
use App\Models\Storage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A placement group (docs/adr/0020): its nodes must belong to clusters of
 * its location, its storages to its nodes and take VM disks.
 */
class PlacementGroupRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['required', 'string', Rule::exists('locations', 'uuid')],
            'node_ids' => ['required', 'array', 'min:1'],
            'node_ids.*' => ['integer', 'distinct', Rule::exists('nodes', 'id')],
            // Empty: each node's storages marked "use for servers".
            'storage_ids' => ['array'],
            'storage_ids.*' => ['integer', 'distinct', Rule::exists('storages', 'id')],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $location = $this->location();
            $outside = Node::query()->whereIn('id', $this->nodeIds())
                ->whereDoesntHave('cluster', fn ($query) => $query->where('location_id', $location->id))
                ->exists();

            if ($outside) {
                $validator->errors()->add('node_ids', __('Choose only nodes of clusters in this location.'));
            }

            $storages = Storage::query()->whereIn('id', $this->storageIds())->get();

            if ($storages->contains(fn (Storage $storage) => ! in_array($storage->node_id, $this->nodeIds(), true) || ! $storage->supportsVmDisks())) {
                $validator->errors()->add('storage_ids', __('Choose only storages of the chosen nodes that take VM disks.'));
            }
        }];
    }

    public function location(): Location
    {
        return Location::query()->where('uuid', $this->string('location'))->firstOrFail();
    }

    /**
     * @return list<int>
     */
    public function nodeIds(): array
    {
        return array_values(array_map('intval', $this->array('node_ids')));
    }

    /**
     * @return list<int>
     */
    public function storageIds(): array
    {
        return array_values(array_map('intval', $this->array('storage_ids')));
    }

    /**
     * @return array<string, mixed>
     */
    public function groupData(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->toString() : null,
            'location_id' => $this->location()->id,
        ];
    }
}
