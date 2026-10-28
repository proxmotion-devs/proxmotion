<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Models\Location;
use Illuminate\Validation\Rule;

/**
 * The location filter of a report: a location's uuid, or none for all.
 */
trait FiltersByLocation
{
    /**
     * @return array<string, mixed>
     */
    protected function locationRules(): array
    {
        return ['location' => ['nullable', 'string', Rule::exists('locations', 'uuid')]];
    }

    /**
     * @return array{label: string, all: string|null, options: list<array{value: string|int, label: string}>}
     */
    protected function locationOptions(): array
    {
        return [
            'label' => __('Location'),
            'all' => __('All locations'),
            'options' => array_values(Location::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Location $location) => ['value' => $location->uuid, 'label' => $location->name])
                ->values()
                ->all()),
        ];
    }

    protected function locationId(string|int|null $uuid): ?int
    {
        return $uuid === null ? null : Location::query()->where('uuid', $uuid)->value('id');
    }
}
