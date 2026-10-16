<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\BillingSystem;
use App\Models\User;
use App\Support\Network\Cidr;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * API tokens of a billing system (docs/adr/0008), listed on the billing
 * systems page. The secret is shown once, right after the token is created;
 * revoking is immediate and cannot be undone.
 */
class ApiTokenController extends Controller
{
    public function store(Request $request, BillingSystem $billingSystem, #[CurrentUser] User $admin): RedirectResponse
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
            billingSystem: $billingSystem,
            name: $validated['name'],
            scopes: array_values(array_map(fn (string $scope) => ApiScope::from($scope), $validated['scopes'])),
            allowedIps: $this->lines($validated['allowed_ips'] ?? null),
            expiresAt: isset($validated['expires_at']) ? Carbon::parse($validated['expires_at'])->endOfDay() : null,
            creator: $admin,
        );

        AuditLog::record('api_token.created', $admin, $token, [
            'name' => $token->name,
            'billing_system' => $billingSystem->name,
            'scopes' => $token->scopes,
        ], $request->ip());

        $this->toast('success', 'API token :name created.', ['name' => $token->name]);

        return to_route('admin.billing-systems.index')->with('new_api_token', [
            'billing_system' => $billingSystem->uuid,
            'name' => $token->name,
            'secret' => $secret,
        ]);
    }

    public function destroy(Request $request, ApiToken $apiToken, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($apiToken->revoked_at === null) {
            $apiToken->forceFill(['revoked_at' => now()])->save();
            AuditLog::record('api_token.revoked', $admin, $apiToken, ['name' => $apiToken->name], $request->ip());
        }

        $this->toast('success', 'API token :name revoked.', ['name' => $apiToken->name]);

        return to_route('admin.billing-systems.index');
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
