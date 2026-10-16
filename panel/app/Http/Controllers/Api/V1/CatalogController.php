<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\LocationResource;
use App\Http\Resources\Api\V1\OsTemplateResource;
use App\Http\Resources\Api\V1\PackageResource;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What the billing system can offer: active packages and OS templates, and locations.
 */
class CatalogController extends ApiController
{
    public function packages(): AnonymousResourceCollection
    {
        return PackageResource::collection(Package::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get());
    }

    public function locations(): AnonymousResourceCollection
    {
        return LocationResource::collection(Location::query()->orderBy('name')->get());
    }

    public function osTemplates(): AnonymousResourceCollection
    {
        return OsTemplateResource::collection(OsTemplate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get());
    }
}
