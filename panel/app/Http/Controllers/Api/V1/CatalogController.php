<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Servers\ServerReinstaller;
use App\Http\Resources\Api\V1\FirewallRulesetResource;
use App\Http\Resources\Api\V1\LocationResource;
use App\Http\Resources\Api\V1\OsTemplateResource;
use App\Http\Resources\Api\V1\PackageResource;
use App\Models\FirewallRuleset;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * What the billing system can offer: active packages, OS templates and
 * firewall rule sets, and locations.
 */
class CatalogController extends ApiController
{
    public function packages(): AnonymousResourceCollection
    {
        return PackageResource::collection(Package::query()->where('is_active', true)->with('osTemplates:id,uuid')->orderBy('sort_order')->orderBy('name')->get());
    }

    public function locations(): AnonymousResourceCollection
    {
        return LocationResource::collection(Location::query()->orderBy('name')->get());
    }

    /**
     * Active firewall rule sets customers can apply (docs/adr/0035 §3).
     */
    public function firewallRulesets(): AnonymousResourceCollection
    {
        return FirewallRulesetResource::collection(FirewallRuleset::query()->where('is_active', true)->orderBy('name')->get());
    }

    /**
     * Active OS templates. "package" limits them to those the package's servers
     * may use; "server" to those the server may be reinstalled with (its package,
     * its cluster and, for a server of a billing system, active ones only). Both
     * are optional and exclusive (docs/adr/0040).
     */
    public function osTemplates(Request $request, ServerReinstaller $reinstaller): AnonymousResourceCollection
    {
        $token = $this->token($request);
        $request->validate([
            'package' => ['nullable', 'uuid', 'prohibits:server', Rule::exists('packages', 'uuid')->where('is_active', true)],
            'server' => ['nullable', 'uuid', 'prohibits:package', Rule::exists('servers', 'uuid')->where('billing_system_id', $token->billing_system_id)],
        ]);

        if ($request->filled('server')) {
            $server = Server::query()->where('uuid', $request->string('server'))->firstOrFail();

            return OsTemplateResource::collection($reinstaller->eligibleTemplates($server));
        }

        $query = OsTemplate::query()->where('is_active', true);

        if ($request->filled('package')) {
            $query->allowedBy(Package::query()->where('uuid', $request->string('package'))->firstOrFail());
        }

        return OsTemplateResource::collection($query->orderBy('sort_order')->orderBy('name')->get());
    }
}
