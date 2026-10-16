<?php

namespace Tests\Feature\Admin;

use App\Enums\ApiScope;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\BillingSystem;
use App\Models\Server;
use Inertia\Testing\AssertableInertia as Assert;

class BillingSystemManagementTest extends AdminTestCase
{
    public function test_billing_systems_are_created_renamed_and_listed_with_their_tokens()
    {
        $this->post(route('admin.billing-systems.store'), ['name' => 'WHMCS'])->assertRedirect(route('admin.billing-systems.index'));
        $this->post(route('admin.billing-systems.store'), ['name' => 'WHMCS'])->assertSessionHasErrors('name');

        $system = BillingSystem::query()->sole();
        $this->assertSame(1, AuditLog::query()->where('action', 'billing_system.created')->count());

        $this->put(route('admin.billing-systems.update', $system), ['name' => 'WHMCS (billing.example.com)'])->assertSessionHasNoErrors();
        $this->assertSame('WHMCS (billing.example.com)', $system->refresh()->name);

        Server::factory()->create(['billing_system_id' => $system->id, 'external_id' => '1']);
        ApiToken::issue($system, 'Cron', [ApiScope::ServersRead]);

        $this->get(route('admin.billing-systems.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-systems/Index')
                ->where('billingSystems.0.name', 'WHMCS (billing.example.com)')
                ->where('billingSystems.0.servers_count', 1)
                ->where('billingSystems.0.tokens.0.name', 'Cron')
                ->missing('billingSystems.0.tokens.0.token_hash')
                ->has('scopes', count(ApiScope::cases()))
                ->where('newToken', null));
    }

    public function test_a_token_is_created_for_a_billing_system_and_its_secret_shown_once()
    {
        $system = BillingSystem::factory()->create(['name' => 'WHMCS']);

        $this->post(route('admin.billing-systems.tokens.store', $system), [
            'name' => 'Module',
            'scopes' => ['servers:read', 'servers:write'],
            'allowed_ips' => "198.51.100.7\n2001:db8::/32, 203.0.113.0/24\n198.51.100.7",
            'expires_at' => now()->addYear()->toDateString(),
        ])->assertRedirect(route('admin.billing-systems.index'));

        $token = ApiToken::query()->sole();
        $this->assertSame($system->id, $token->billing_system_id);
        $this->assertSame(['servers:read', 'servers:write'], $token->scopes);
        $this->assertSame(['198.51.100.7', '2001:db8::/32', '203.0.113.0/24'], $token->allowed_ips);
        $this->assertSame($this->admin->id, $token->created_by);
        $this->assertTrue($token->expires_at?->isSameDay(now()->addYear()));

        $secret = null;
        $this->get(route('admin.billing-systems.index'))
            ->assertInertia(function (Assert $page) use (&$secret, $system) {
                $page->where('newToken.billing_system', $system->uuid)->where('newToken.name', 'Module');
                $secret = $page->toArray()['props']['newToken']['secret'];
            });

        $this->assertIsString($secret);
        $this->assertSame($token->id, ApiToken::findActive($secret)?->id);

        // Shown once: the next visit has no secret.
        $this->get(route('admin.billing-systems.index'))->assertInertia(fn (Assert $page) => $page->where('newToken', null));

        $log = AuditLog::query()->where('action', 'api_token.created')->sole();
        $this->assertSame(['name' => 'Module', 'billing_system' => 'WHMCS', 'scopes' => ['servers:read', 'servers:write']], $log->properties);
        $this->assertStringNotContainsString($secret, (string) json_encode($log->properties));
    }

    public function test_the_token_form_is_validated()
    {
        $system = BillingSystem::factory()->create();

        $this->post(route('admin.billing-systems.tokens.store', $system), [
            'name' => '',
            'scopes' => ['servers:delete-everything'],
            'allowed_ips' => "198.51.100.7\nnot-an-address\n10.0.0.0/33",
            'expires_at' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors(['name', 'scopes.0', 'allowed_ips', 'expires_at']);

        $this->post(route('admin.billing-systems.tokens.store', $system), ['name' => 'No scopes', 'scopes' => []])->assertSessionHasErrors(['scopes']);

        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_a_token_can_be_revoked()
    {
        [$token, $secret] = ApiToken::issue(BillingSystem::factory()->create(), 'Module', ApiScope::cases());

        $this->delete(route('admin.api-tokens.destroy', $token))->assertRedirect(route('admin.billing-systems.index'));

        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertNull(ApiToken::findActive($secret));
        $this->assertSame(1, AuditLog::query()->where('action', 'api_token.revoked')->count());

        // Revoking again changes nothing.
        $this->delete(route('admin.api-tokens.destroy', $token));
        $this->assertSame(1, AuditLog::query()->where('action', 'api_token.revoked')->count());

        $this->get(route('admin.billing-systems.index'))->assertInertia(fn (Assert $page) => $page
            ->where('billingSystems.0.tokens.0.revoked', true)
            ->where('billingSystems.0.tokens.0.active', false));
    }
}
