<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Network\Cidr;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tokens of the billing API (docs/adr/0008). The secret is shown once, right
 * after the token is created; revoking is immediate and cannot be undone.
 */
class ApiTokenController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/api-tokens/Index', [
            'tokens' => ApiToken::query()
                ->with('creator:id,name')
                ->latest('id')
                ->get()
                ->map(fn (ApiToken $token) => [
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
            'scopes' => array_map(fn (ApiScope $scope) => ['value' => $scope->value, 'label' => $scope->label()], ApiScope::cases()),
            'newToken' => session('new_api_token'),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $admin): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', Rule::enum(ApiScope::class)],
            // One address or CIDR per line; empty: any address.
            'allowed_ips' => ['nullable', 'string', 'max:4096', function (string $attribute, mixed $value, Closure $fail) {
                foreach ($this->lines($value) as $line) {
                    if (filter_var($line, FILTER_VALIDATE_IP) === false && ! (str_contains($line, '/') && Cidr::isValid($line))) {
                        $fail(__(':value is not an IP address or a network in CIDR notation.', ['value' => $line]));
                    }
                }
            }],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        [$token, $secret] = ApiToken::issue(
            name: $validated['name'],
            scopes: array_values(array_map(fn (string $scope) => ApiScope::from($scope), $validated['scopes'])),
            allowedIps: $this->lines($validated['allowed_ips'] ?? null),
            expiresAt: isset($validated['expires_at']) ? Carbon::parse($validated['expires_at'])->endOfDay() : null,
            creator: $admin,
        );

        AuditLog::record('api_token.created', $admin, $token, ['name' => $token->name, 'scopes' => $token->scopes], $request->ip());

        $this->toast('success', 'API token :name created.', ['name' => $token->name]);

        return to_route('admin.api-tokens.index')->with('new_api_token', ['name' => $token->name, 'secret' => $secret]);
    }

    public function destroy(Request $request, ApiToken $apiToken, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($apiToken->revoked_at === null) {
            $apiToken->forceFill(['revoked_at' => now()])->save();
            AuditLog::record('api_token.revoked', $admin, $apiToken, ['name' => $apiToken->name], $request->ip());
        }

        $this->toast('success', 'API token :name revoked.', ['name' => $apiToken->name]);

        return to_route('admin.api-tokens.index');
    }

    /**
     * @return list<string>
     */
    private function lines(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(trim(...), preg_split('/[\s,]+/', $value) ?: []))));
    }
}
