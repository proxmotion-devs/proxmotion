<?php

namespace Tests\Feature\Api\V1;

use App\Models\BillingSystem;
use App\Models\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Text that would change a query built by concatenation stays a value:
 * it finds nothing, breaks nothing and shows no other billing system's
 * servers. The literal SQL rule (tests/PHPStan/LiteralSqlRule.php) keeps
 * queries from being built that way.
 */
class SqlInjectionTest extends ApiTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function payloads(): array
    {
        return [
            'always true' => ["' OR '1'='1"],
            'comment' => ["whmcs-1' -- "],
            'union' => ["' UNION SELECT id, uuid FROM users -- "],
            'stacked' => ["x'; DROP TABLE servers; -- "],
            'double quote' => ['" OR ""="'],
            'wildcard' => ['%'],
            'backslash' => ['\\'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_lookups_by_billing_ids_treat_the_text_as_a_value(string $payload)
    {
        $this->provisionedServer('whmcs-1');
        $this->provisionedServer('other-1', BillingSystem::factory()->create());

        foreach (['external_id', 'external_client_id'] as $filter) {
            $this->api('GET', '/api/v1/servers?'.http_build_query([$filter => $payload]))
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }

        $this->assertSame(2, Server::query()->count());
    }

    #[DataProvider('payloads')]
    public function test_identifiers_in_the_path_treat_the_text_as_a_value(string $payload)
    {
        $this->provisionedServer();

        $this->api('GET', '/api/v1/servers/'.rawurlencode($payload))->assertNotFound();
        $this->api('GET', '/api/v1/tasks/'.rawurlencode($payload))->assertNotFound();
        $this->api('GET', '/api/v1/apps?'.http_build_query(['server' => $payload]))->assertUnprocessable();

        $this->assertSame(1, Server::query()->count());
    }
}
