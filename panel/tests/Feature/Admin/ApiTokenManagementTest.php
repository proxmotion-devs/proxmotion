<?php

namespace Tests\Feature\Admin;

use App\Enums\ApiScope;
use App\Models\ApiToken;
use App\Models\AuditLog;
use Inertia\Testing\AssertableInertia as Assert;

class ApiTokenManagementTest extends AdminTestCase
{
    public function test_a_token_is_created_and_its_secret_shown_once()
    {
        $this->post(route('admin.api-tokens.store'), [
            'name' => 'WHMCS',
            'scopes' => ['servers:read', 'servers:write'],
            'allowed_ips' => "198.51.100.7\n2001:db8::/32, 203.0.113.0/24\n198.51.100.7",
            'expires_at' => now()->addYear()->toDateString(),
        ])->assertRedirect(route('admin.api-tokens.index'));

        $token = ApiToken::query()->sole();
        $this->assertSame(['servers:read', 'servers:write'], $token->scopes);
        $this->assertSame(['198.51.100.7', '2001:db8::/32', '203.0.113.0/24'], $token->allowed_ips);
        $this->assertSame($this->admin->id, $token->created_by);
        $this->assertTrue($token->expires_at?->isSameDay(now()->addYear()));

        $secret = null;
        $this->get(route('admin.api-tokens.index'))
            ->assertInertia(function (Assert $page) use (&$secret, $token) {
                $page->component('admin/api-tokens/Index')
                    ->where('newToken.name', 'WHMCS')
                    ->where('tokens.0.prefix', $token->token_prefix)
                    ->missing('tokens.0.token_hash')
                    ->has('scopes', count(ApiScope::cases()));
                $secret = $page->toArray()['props']['newToken']['secret'];
            });

        $this->assertIsString($secret);
        $this->assertSame($token->id, ApiToken::findActive($secret)?->id);

        // Shown once: the next visit has no secret.
        $this->get(route('admin.api-tokens.index'))->assertInertia(fn (Assert $page) => $page->where('newToken', null));

        $log = AuditLog::query()->where('action', 'api_token.created')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertStringNotContainsString($secret, (string) json_encode($log->properties));
    }

    public function test_the_form_is_validated()
    {
        $this->post(route('admin.api-tokens.store'), [
            'name' => '',
            'scopes' => ['servers:delete-everything'],
            'allowed_ips' => "198.51.100.7\nnot-an-address\n10.0.0.0/33",
            'expires_at' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors(['name', 'scopes.0', 'allowed_ips', 'expires_at']);

        $this->post(route('admin.api-tokens.store'), ['name' => 'No scopes', 'scopes' => []])->assertSessionHasErrors(['scopes']);

        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_a_token_can_be_revoked()
    {
        [$token, $secret] = ApiToken::issue('WHMCS', ApiScope::cases());

        $this->delete(route('admin.api-tokens.destroy', $token))->assertRedirect(route('admin.api-tokens.index'));

        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertNull(ApiToken::findActive($secret));
        $this->assertSame(1, AuditLog::query()->where('action', 'api_token.revoked')->count());

        // Revoking again changes nothing.
        $this->delete(route('admin.api-tokens.destroy', $token));
        $this->assertSame(1, AuditLog::query()->where('action', 'api_token.revoked')->count());

        $this->get(route('admin.api-tokens.index'))->assertInertia(fn (Assert $page) => $page
            ->where('tokens.0.revoked', true)
            ->where('tokens.0.active', false));
    }
}
