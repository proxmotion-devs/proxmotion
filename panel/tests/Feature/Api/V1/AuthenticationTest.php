<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiScope;
use App\Models\ApiToken;

/**
 * Who may call the API at all: tokens, revocation, expiry, allowed
 * addresses and throttling (docs/adr/0008).
 */
class AuthenticationTest extends ApiTestCase
{
    public function test_requests_without_a_token_are_refused()
    {
        $this->getJson('/api/v1/packages')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_wrong_and_malformed_tokens_are_refused()
    {
        $this->api('GET', '/api/v1/packages', secret: 'pmx_'.str_repeat('a', 48))->assertUnauthorized();
        $this->api('GET', '/api/v1/packages', secret: 'not-a-proxmotion-token')->assertUnauthorized();
        $this->api('GET', '/api/v1/packages', secret: substr($this->secret, 0, -1))->assertUnauthorized();
    }

    public function test_a_valid_token_is_accepted_and_its_use_recorded()
    {
        $this->api('GET', '/api/v1/packages')->assertOk();

        $this->token->refresh();
        $this->assertNotNull($this->token->last_used_at);
        $this->assertSame('127.0.0.1', $this->token->last_used_ip);
    }

    public function test_revoked_and_expired_tokens_are_refused()
    {
        $this->token->forceFill(['revoked_at' => now()])->save();
        $this->api('GET', '/api/v1/packages')->assertUnauthorized();

        [$expired, $secret] = $this->issueToken();
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->api('GET', '/api/v1/packages', secret: $secret)->assertUnauthorized();
    }

    public function test_tokens_can_be_limited_to_addresses_and_networks()
    {
        [, $single] = $this->issueToken(allowedIps: ['198.51.100.7']);
        [, $network] = $this->issueToken(allowedIps: ['198.51.100.0/24', '2001:db8::/32']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->api('GET', '/api/v1/packages', secret: $single)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->api('GET', '/api/v1/packages', secret: $single)
            ->assertForbidden()
            ->assertJson(['message' => 'This token may not be used from your address.']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])->api('GET', '/api/v1/packages', secret: $network)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1::5'])->api('GET', '/api/v1/packages', secret: $network)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->api('GET', '/api/v1/packages', secret: $network)->assertForbidden();
    }

    public function test_repeated_failures_from_one_address_are_throttled()
    {
        for ($i = 0; $i < 20; $i++) {
            $this->api('GET', '/api/v1/packages', secret: 'pmx_wrong')->assertUnauthorized();
        }

        $this->api('GET', '/api/v1/packages', secret: 'pmx_wrong')->assertTooManyRequests()->assertHeader('Retry-After');
        // A valid token from the same address is throttled too until the minute is over.
        $this->api('GET', '/api/v1/packages')->assertTooManyRequests();
    }

    public function test_requests_are_rate_limited_per_token()
    {
        for ($i = 0; $i < 120; $i++) {
            $this->api('GET', '/api/v1/locations')->assertOk();
        }

        $this->api('GET', '/api/v1/locations')->assertTooManyRequests();

        [, $other] = $this->issueToken();
        $this->api('GET', '/api/v1/locations', secret: $other)->assertOk();
    }

    public function test_bad_tokens_and_scopes_never_reveal_whether_a_server_exists()
    {
        $unknown = '/api/v1/servers/00000000-0000-0000-0000-000000000000';

        $this->getJson($unknown)->assertUnauthorized();

        [, $catalogOnly] = $this->issueToken([ApiScope::CatalogRead]);
        $this->api('GET', $unknown, secret: $catalogOnly)->assertForbidden();

        $this->api('GET', $unknown)->assertNotFound();
    }

    public function test_only_a_hash_of_the_secret_is_stored()
    {
        $this->assertStringStartsWith(ApiToken::PREFIX, $this->secret);
        $this->assertSame(hash('sha256', $this->secret), $this->token->token_hash);
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $this->secret]);
        $this->assertArrayNotHasKey('token_hash', $this->token->toArray());
    }
}
