<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiScope;
use App\Models\ApiToken;
use App\Models\Server;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Billing API tests: the infrastructure of BuildsInfrastructure, an
 * in-memory Proxmox with template 9000, and a token with every scope.
 */
abstract class ApiTestCase extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    protected FakeProxmoxCluster $proxmox;

    protected ApiToken $token;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        [$this->token, $this->secret] = $this->issueToken();
    }

    /**
     * @param  list<ApiScope>|null  $scopes  null: every scope
     * @param  list<string>|null  $allowedIps
     * @return array{ApiToken, string}
     */
    protected function issueToken(?array $scopes = null, ?array $allowedIps = null): array
    {
        return ApiToken::issue('WHMCS', $scopes ?? ApiScope::cases(), $allowedIps);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $uri, array $data = [], ?string $secret = null, array $headers = []): TestResponse
    {
        return $this->withToken($secret ?? $this->secret)->json($method, $uri, $data, $headers);
    }

    /**
     * A server created through the panel's own pipeline (on the fake Proxmox).
     */
    protected function provisionedServer(string $externalId = 'whmcs-1001'): Server
    {
        $server = app(ServerCreator::class)->create($this->order(['externalId' => $externalId, 'externalClientId' => 'client-7']));

        return $server->refresh();
    }
}
