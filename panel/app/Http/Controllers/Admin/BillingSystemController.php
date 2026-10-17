<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\BillingSystem;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing systems and their API tokens (docs/adr/0008). A token only sees
 * the servers of its billing system.
 */
class BillingSystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/billing-systems/Index', [
            'billingSystems' => BillingSystem::query()
                ->withCount('servers')
                ->with([
                    'apiTokens' => fn ($query) => $query->with('creator:id,name')->latest('id'),
                    'webhookEndpoints' => fn ($query) => $query->with('lastDelivery')->oldest('id'),
                ])
                ->orderBy('name')
                ->get()
                ->map(fn (BillingSystem $system) => [
                    'uuid' => $system->uuid,
                    'name' => $system->name,
                    'servers_count' => $system->servers_count,
                    'tokens' => $system->apiTokens->map(fn (ApiToken $token) => [
                        'uuid' => $token->uuid,
                        'name' => $token->name,
                        'prefix' => $token->token_prefix,
                        'scopes' => $token->scopes,
                        'allowed_ips' => $token->allowed_ips ?? [],
                        'creator' => $token->creator?->name,
                        'active' => $token->isActive(),
                        'revoked' => $token->revoked_at !== null,
                        'last_used_at' => $token->last_used_at?->toIso8601String(),
                        'last_used_ip' => $token->last_used_ip,
                        'expires_at' => $token->expires_at?->toIso8601String(),
                        'created_at' => $token->created_at?->toIso8601String(),
                    ]),
                    'webhooks' => $system->webhookEndpoints->map(fn (WebhookEndpoint $endpoint) => WebhookEndpointController::present($endpoint)),
                ]),
            'webhookEvents' => WebhookEndpointController::eventOptions(),
            'scopes' => array_map(fn (ApiScope $scope) => ['value' => $scope->value, 'label' => $scope->label()], ApiScope::cases()),
            'newToken' => session('new_api_token'),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $admin): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('billing_systems', 'name')],
        ]);

        $system = BillingSystem::query()->create($validated);
        AuditLog::record('billing_system.created', $admin, $system, ['name' => $system->name], $request->ip());

        $this->toast('success', 'Billing system :name created.', ['name' => $system->name]);

        return to_route('admin.billing-systems.index');
    }

    public function update(Request $request, BillingSystem $billingSystem): RedirectResponse
    {
        $billingSystem->update($request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('billing_systems', 'name')->ignore($billingSystem->id)],
        ]));

        $this->toast('success', 'Billing system :name saved.', ['name' => $billingSystem->name]);

        return to_route('admin.billing-systems.index');
    }
}
