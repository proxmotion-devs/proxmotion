<?php

namespace Tests\Feature\Api\V1;

use App\Models\Server;
use App\Models\Task;

/**
 * POST /api/v1/servers/{id}/primary-address (docs/adr/0011).
 */
class PrimaryAddressTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->provisionedServer();
    }

    private function url(): string
    {
        return "/api/v1/servers/{$this->server->uuid}/primary-address";
    }

    public function test_the_primary_is_replaced_and_shown_first()
    {
        [$old] = $this->server->ipAddresses()->pluck('address')->all();

        $this->api('POST', $this->url(), ['ip_version' => 4])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'change_primary_address')
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertSame($this->token->id, Task::query()->latest('id')->firstOrFail()->api_token_id);
        $response = $this->api('GET', "/api/v1/servers/{$this->server->uuid}")
            ->assertJsonCount(1, 'data.addresses')
            ->assertJsonPath('data.addresses.0.primary', true);
        $this->assertNotSame($old, $response->json('data.addresses.0.address'));
    }

    public function test_unusable_addresses_and_missing_fields_are_refused()
    {
        $this->api('POST', $this->url(), ['address' => '198.51.100.5'])
            ->assertStatus(422)
            ->assertJsonPath('message', '198.51.100.5 is not a free address of the pools this server can use.');
        $this->api('POST', $this->url(), [])->assertStatus(422)->assertJsonValidationErrors('ip_version');
        $this->api('POST', $this->url(), ['address' => 'not-an-ip'])->assertStatus(422)->assertJsonValidationErrors('address');
    }

    public function test_suspended_servers_are_a_conflict()
    {
        $this->server->forceFill(['status' => 'suspended'])->save();

        $this->api('POST', $this->url(), ['ip_version' => 4])->assertStatus(409);
    }
}
