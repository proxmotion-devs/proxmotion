<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppEntitlementSource;
use App\Models\ApiToken;
use App\Models\AppEntitlement;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rights to install paid apps on a server (docs/adr/0019). Granting is
 * idempotent: a server keeps one active entitlement per app. Revoking only
 * stops new installs; installed apps stay.
 */
final class AppEntitlements
{
    public function has(Server $server, AppRecipe $app): bool
    {
        return $server->appEntitlements()->active()->where('app_recipe_id', $app->id)->exists();
    }

    /**
     * @return array{AppEntitlement, bool} the active entitlement, and whether it was granted now
     *
     * @throws AppNotEligible for free apps
     */
    public function grant(Server $server, AppRecipe $app, AppEntitlementSource $source, User|ApiToken|null $actor, ?string $reference = null, ?string $ipAddress = null): array
    {
        if ($app->recipe()->billing !== 'paid') {
            throw new AppNotEligible('This app is free and needs no entitlement.');
        }

        return DB::transaction(function () use ($server, $app, $source, $actor, $reference, $ipAddress) {
            // Serialises grants for the server, so two requests never make two active entitlements.
            Server::query()->whereKey($server->id)->lockForUpdate()->first();

            $active = $server->appEntitlements()->active()->where('app_recipe_id', $app->id)->first();

            if ($active !== null) {
                return [$active, false];
            }

            $entitlement = new AppEntitlement;
            $entitlement->forceFill([
                'server_id' => $server->id,
                'app_recipe_id' => $app->id,
                'source' => $source,
                'reference' => $reference,
                'user_id' => $actor instanceof User ? $actor->id : null,
                'api_token_id' => $actor instanceof ApiToken ? $actor->id : null,
            ])->save();

            AuditLog::record('app.entitlement_granted', $actor, $server, ['app' => $app->slug, 'source' => $source->value, 'reference' => $reference], $ipAddress);

            return [$entitlement, true];
        });
    }

    /**
     * @return AppEntitlement|null the revoked entitlement, or null when there was none
     */
    public function revoke(Server $server, AppRecipe $app, User|ApiToken|null $actor, ?string $ipAddress = null): ?AppEntitlement
    {
        return DB::transaction(function () use ($server, $app, $actor, $ipAddress) {
            $active = $server->appEntitlements()->active()->where('app_recipe_id', $app->id)->lockForUpdate()->first();

            if ($active === null) {
                return null;
            }

            $active->forceFill(['revoked_at' => now()])->save();
            AuditLog::record('app.entitlement_revoked', $actor, $server, ['app' => $app->slug, 'reference' => $active->reference], $ipAddress);

            return $active;
        });
    }
}
