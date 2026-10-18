<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Apps\AppEntitlements;
use App\Domain\Apps\AppNotEligible;
use App\Enums\AppEntitlementSource;
use App\Enums\ServerStatus;
use App\Http\Resources\Api\V1\AppEntitlementResource;
use App\Models\AppEntitlement;
use App\Models\AppRecipe;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Rights to install paid apps on a server (docs/adr/0019), granted when the
 * customer buys the app in the billing system (e.g. an addon) and revoked
 * when that ends. Revoking never removes an installed app.
 */
class AppEntitlementController extends ApiController
{
    public function __construct(private readonly AppEntitlements $entitlements) {}

    /**
     * The server's entitlements, active ones and the latest revoked ones.
     */
    public function index(Server $server): AnonymousResourceCollection
    {
        $entitlements = $server->appEntitlements()->with('recipe')->latest('id')->limit(50)->get();

        return AppEntitlementResource::collection($entitlements->each(fn (AppEntitlement $entitlement) => $entitlement->setRelation('server', $server)));
    }

    /**
     * 201 with a new entitlement, or 200 with the one the server already has.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'app' => ['required', 'string', Rule::exists('app_recipes', 'slug')],
            'reference' => ['nullable', 'string', 'max:191'],
        ]);

        if ($server->status === ServerStatus::Terminated) {
            return $this->notPossible($server);
        }

        $app = AppRecipe::query()->with('latestVersion')->where('slug', $data['app'])->firstOrFail();

        try {
            [$entitlement, $created] = $this->entitlements->grant($server, $app, AppEntitlementSource::Api, $this->token($request), $data['reference'] ?? null, $request->ip());
        } catch (AppNotEligible $e) {
            return response()->json(['message' => $e->translatedMessage(), 'errors' => ['app' => [$e->translatedMessage()]]], 422);
        }

        return AppEntitlementResource::make($entitlement->load('recipe')->setRelation('server', $server))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /**
     * Revoke the server's active entitlement to the app; 404 when it has none.
     */
    public function destroy(Request $request, Server $server, string $app): JsonResponse
    {
        $recipe = AppRecipe::query()->where('slug', $app)->firstOrFail();
        $revoked = $this->entitlements->revoke($server, $recipe, $this->token($request), $request->ip());

        if ($revoked === null) {
            return response()->json(['message' => 'The server has no active entitlement to this app.'], 404);
        }

        return AppEntitlementResource::make($revoked->load('recipe')->setRelation('server', $server))->response();
    }
}
