<?php

namespace Tests\Feature\Api\V1;

use App\Models\BillingSystem;
use App\Models\Server;
use App\Provisioning\ServerCreator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Several billing systems on one panel (docs/adr/0008): a token only
 * reaches the servers of its own billing system; for anything else it gets
 * the same 404 as for a server that does not exist.
 */
class BillingSystemIsolationTest extends ApiTestCase
{
    private BillingSystem $otherSystem;

    private Server $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otherSystem = BillingSystem::factory()->create(['name' => 'WISECP']);
        $this->foreign = $this->provisionedServer('1001', $this->otherSystem);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function serverEndpoints(): array
    {
        return [
            'show' => ['GET', ''],
            'terminate' => ['DELETE', ''],
            'suspend' => ['POST', '/suspend'],
            'unsuspend' => ['POST', '/unsuspend'],
            'power' => ['POST', '/power'],
            'reinstall' => ['POST', '/reinstall'],
            'change resources' => ['POST', '/package'],
            'primary address' => ['POST', '/primary-address'],
            'reset the password' => ['POST', '/password'],
            'fetch the password' => ['GET', '/password'],
            'rDNS' => ['POST', '/rdns'],
            'console link' => ['POST', '/console'],
        ];
    }

    #[DataProvider('serverEndpoints')]
    public function test_servers_of_another_billing_system_look_like_unknown_ones(string $method, string $suffix)
    {
        // As in production: without the debug trace, which shows where the 404 was raised.
        config(['app.debug' => false]);

        $foreign = $this->api($method, "/api/v1/servers/{$this->foreign->uuid}{$suffix}", ['action' => 'stop']);
        $unknown = $this->api($method, "/api/v1/servers/00000000-0000-0000-0000-000000000000{$suffix}", ['action' => 'stop']);

        $foreign->assertNotFound();
        $unknown->assertNotFound();
        $this->assertSame(
            str_replace($this->foreign->uuid, 'ID', (string) $foreign->getContent()),
            str_replace('00000000-0000-0000-0000-000000000000', 'ID', (string) $unknown->getContent()),
            'The answer tells a foreign server from an unknown one.',
        );

        $this->assertSame(1, $this->foreign->tasks()->count(), 'Only the create task.');
        $this->assertSame('running', $this->proxmox->vms[$this->foreign->vmid]['status']);
        $this->assertTrue($this->foreign->refresh()->root_password_unrevealed);
    }

    public function test_tasks_of_another_billing_system_are_not_found()
    {
        $this->api('GET', '/api/v1/tasks/'.$this->foreign->tasks()->sole()->uuid)->assertNotFound();
    }

    public function test_the_list_only_contains_the_tokens_own_servers()
    {
        $own = $this->provisionedServer('1001');

        $this->api('GET', '/api/v1/servers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->uuid);
        $this->api('GET', '/api/v1/servers?external_id=1001')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->uuid);

        [, $other] = $this->issueToken(billingSystem: $this->otherSystem);
        $this->api('GET', '/api/v1/servers?external_id=1001', secret: $other)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->foreign->uuid);
    }

    public function test_service_ids_are_unique_per_billing_system()
    {
        $payload = [
            'external_id' => '1001',
            'package' => $this->package->uuid,
            'location' => $this->location->uuid,
            'os_template' => $this->osTemplate->uuid,
            'hostname' => 'web2.example.com',
        ];

        // The other billing system already has a service 1001; this one does not.
        $this->api('POST', '/api/v1/servers', $payload)->assertAccepted();
        $this->assertSame($this->billingSystem->id, Server::query()->latest('id')->firstOrFail()->billing_system_id);

        $this->api('POST', '/api/v1/servers', [...$payload, 'hostname' => 'web3.example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_id']);
    }

    public function test_servers_without_a_billing_system_are_not_reachable_through_the_api()
    {
        $adminOnly = app(ServerCreator::class)->create($this->order(['hostname' => 'internal.example.com']));

        $this->api('GET', "/api/v1/servers/{$adminOnly->uuid}")->assertNotFound();
        $this->api('GET', '/api/v1/servers')->assertJsonCount(0, 'data');
    }

    public function test_a_new_token_of_the_same_billing_system_keeps_its_servers()
    {
        $this->token->forceFill(['revoked_at' => now()])->save();
        [, $replacement] = $this->issueToken();
        $own = $this->provisionedServer('2002');

        $this->api('GET', "/api/v1/servers/{$own->uuid}", secret: $replacement)->assertOk();
    }
}
