<?php

namespace Database\Factories;

use App\Enums\ApiScope;
use App\Models\ApiToken;
use App\Models\BillingSystem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Tokens with a random secret that is not known to the test. Use
 * ApiToken::issue() when the test needs to send the secret.
 *
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $secret = ApiToken::PREFIX.Str::random(48);

        return [
            'billing_system_id' => BillingSystem::factory(),
            'name' => fake()->words(2, true),
            'token_hash' => ApiToken::hash($secret),
            'token_prefix' => substr($secret, 0, 12),
            'scopes' => array_map(fn (ApiScope $scope) => $scope->value, ApiScope::cases()),
            'allowed_ips' => null,
        ];
    }
}
