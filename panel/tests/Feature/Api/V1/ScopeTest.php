<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiScope;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every endpoint refuses a token without its scope (AGENTS.md 7.1).
 */
class ScopeTest extends ApiTestCase
{
    /**
     * @return array<string, array{string, string, ApiScope}>
     */
    public static function endpoints(): array
    {
        return [
            'list packages' => ['GET', '/api/v1/packages', ApiScope::CatalogRead],
            'list locations' => ['GET', '/api/v1/locations', ApiScope::CatalogRead],
            'list OS templates' => ['GET', '/api/v1/os-templates', ApiScope::CatalogRead],
            'list servers' => ['GET', '/api/v1/servers', ApiScope::ServersRead],
            'show a server' => ['GET', '/api/v1/servers/{server}', ApiScope::ServersRead],
            'show a task' => ['GET', '/api/v1/tasks/{task}', ApiScope::ServersRead],
            'create a server' => ['POST', '/api/v1/servers', ApiScope::ServersWrite],
            'terminate' => ['DELETE', '/api/v1/servers/{server}', ApiScope::ServersWrite],
            'suspend' => ['POST', '/api/v1/servers/{server}/suspend', ApiScope::ServersWrite],
            'unsuspend' => ['POST', '/api/v1/servers/{server}/unsuspend', ApiScope::ServersWrite],
            'power' => ['POST', '/api/v1/servers/{server}/power', ApiScope::ServersWrite],
            'reinstall' => ['POST', '/api/v1/servers/{server}/reinstall', ApiScope::ServersWrite],
            'change resources' => ['POST', '/api/v1/servers/{server}/package', ApiScope::ServersWrite],
            'reset the password' => ['POST', '/api/v1/servers/{server}/password', ApiScope::ServersWrite],
            'set rDNS' => ['POST', '/api/v1/servers/{server}/rdns', ApiScope::ServersWrite],
            'fetch the password' => ['GET', '/api/v1/servers/{server}/password', ApiScope::ServersSecrets],
            'console link' => ['POST', '/api/v1/servers/{server}/console', ApiScope::ServersSecrets],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_the_endpoint_requires_its_scope(string $method, string $uri, ApiScope $scope)
    {
        $server = $this->provisionedServer();
        $uri = str_replace(['{server}', '{task}'], [$server->uuid, $server->tasks()->firstOrFail()->uuid], $uri);
        $tasks = $server->tasks()->count();

        [, $withoutScope] = $this->issueToken(array_values(array_filter(ApiScope::cases(), fn (ApiScope $other) => $other !== $scope)));

        $this->api($method, $uri, secret: $withoutScope)
            ->assertForbidden()
            ->assertExactJson(['message' => "This token does not have the {$scope->value} scope."]);

        $this->assertSame($tasks, $server->tasks()->count(), 'A refused request started a task.');

        [, $onlyScope] = $this->issueToken([$scope]);
        $this->assertNotSame(403, $this->api($method, $uri, secret: $onlyScope)->status(), 'The scope alone is not enough.');
    }
}
