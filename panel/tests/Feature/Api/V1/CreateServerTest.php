<?php

namespace Tests\Feature\Api\V1;

use App\Enums\NetworkModel;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\AuditLog;
use App\Models\IdempotencyKey;
use App\Models\Server;
use App\Models\Task;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use Tests\Unit\PublicKeyTest;

class CreateServerTest extends ApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'external_id' => 'whmcs-2001',
            'external_client_id' => 'client-42',
            'package' => $this->package->uuid,
            'location' => $this->location->uuid,
            'os_template' => $this->osTemplate->uuid,
            'hostname' => 'Web1.Example.com',
            'ssh_keys' => [PublicKeyTest::ED25519],
            ...$overrides,
        ];
    }

    public function test_a_server_is_created_and_its_task_returned()
    {
        $response = $this->api('POST', '/api/v1/servers', $this->payload())->assertAccepted();

        $server = Server::query()->sole();
        $task = Task::query()->sole();

        $response->assertExactJson(['data' => [
            'id' => $task->uuid,
            'type' => 'create',
            'status' => 'succeeded',
            'server' => ['id' => $server->uuid, 'external_id' => 'whmcs-2001', 'hostname' => 'web1.example.com', 'status' => 'active'],
            'steps' => array_map(fn (array $step) => ['key' => $step['key'], 'status' => 'done', 'message' => null], $task->steps),
            'error' => null,
            'created_at' => $task->created_at?->toIso8601String(),
            'started_at' => $task->started_at?->toIso8601String(),
            'finished_at' => $task->finished_at?->toIso8601String(),
        ]]);

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame('web1.example.com', $server->hostname);
        $this->assertSame('client-42', $server->external_client_id);
        $this->assertSame(NetworkModel::Bridged, $server->network_model, 'The first network model of the package.');
        $this->assertSame(4096, $server->memory_mib);
        $this->assertStringContainsString('ssh-ed25519', (string) $server->ssh_keys);
        $this->assertTrue($server->root_password_unrevealed);

        $this->assertSame($this->token->id, $task->api_token_id);
        $this->assertNull($task->user_id);

        $log = AuditLog::query()->where('action', 'server.created')->sole();
        $this->assertSame($this->token->id, $log->api_token_id);
        $this->assertSame(['hostname' => 'web1.example.com', 'external_id' => 'whmcs-2001'], $log->properties);
    }

    public function test_without_a_hostname_the_location_template_names_the_server()
    {
        // docs/adr/0031: no template, so a hostname is required.
        $this->api('POST', '/api/v1/servers', $this->payload(['hostname' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hostname' => "Enter a hostname: the location {$this->location->name} has no hostname template."]);

        $this->location->update(['hostname_template' => 'ip-{ip}.example.com']);

        $this->api('GET', '/api/v1/locations')->assertJsonPath('data.0.generates_hostnames', true);

        $payload = $this->payload();
        unset($payload['hostname']);

        $this->api('POST', '/api/v1/servers', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.server.hostname', 'ip-203-0-113-2.example.com');

        $server = Server::query()->sole();
        $this->assertSame('ip-203-0-113-2.example.com', $server->hostname);
        $this->assertSame('ip-203-0-113-2.example.com', $server->ipAddresses()->sole()->ptr);
        $this->assertSame(['hostname' => 'ip-203-0-113-2.example.com', 'external_id' => 'whmcs-2001'], AuditLog::query()->where('action', 'server.created')->sole()->properties);
    }

    public function test_resources_of_the_package_can_be_overridden()
    {
        $this->addIpv6Pool();

        $this->api('POST', '/api/v1/servers', $this->payload([
            'network_model' => 'bridged',
            'resources' => ['cpu_cores' => 4, 'memory_mib' => 8192, 'disk_mib' => 81920, 'ipv6_count' => 1, 'traffic_bytes' => 2 * 1000 ** 4],
        ]))->assertAccepted();

        $server = Server::query()->sole();
        $this->assertSame(4, $server->cpu_cores);
        $this->assertSame(8192, $server->memory_mib);
        $this->assertSame(81920, $server->disk_mib);
        $this->assertSame(2 * 1000 ** 4, $server->traffic_bytes);
        $this->assertSame(1000, $server->port_speed_mbps, 'Not overridden: from the package.');
        $this->assertSame(2, $server->ipAddresses()->count());
    }

    public function test_a_server_can_be_created_without_a_package()
    {
        $payload = $this->payload([
            'package' => null,
            'network_model' => 'bridged',
            'resources' => ['cpu_cores' => 3, 'memory_mib' => 3072, 'disk_mib' => 30720, 'traffic_bytes' => 5 * 1000 ** 4],
        ]);

        $this->api('POST', '/api/v1/servers', $payload)->assertAccepted()->assertJsonPath('data.status', 'succeeded');

        $server = Server::query()->sole();
        $this->assertNull($server->package_id);
        $this->assertSame(3, $server->cpu_cores);
        $this->assertSame(3072, $server->memory_mib);
        $this->assertSame(30720, $server->disk_mib);
        $this->assertSame(5 * 1000 ** 4, $server->traffic_bytes);
        $this->assertNull($server->port_speed_mbps, 'Not given: unlimited.');
        $this->assertSame(1, $server->ipAddresses()->count(), 'One IPv4 address unless given.');

        $this->api('GET', "/api/v1/servers/{$server->uuid}")->assertJsonPath('data.package', null);
    }

    public function test_without_a_package_the_resources_and_network_model_are_required()
    {
        $this->api('POST', '/api/v1/servers', $this->payload(['package' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['network_model', 'resources.cpu_cores', 'resources.memory_mib', 'resources.disk_mib']);

        $this->api('POST', '/api/v1/servers', $this->payload([
            'package' => null,
            'network_model' => 'bridged',
            'resources' => ['cpu_cores' => 1, 'memory_mib' => 1024, 'disk_mib' => 10240, 'ipv4_count' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['resources.ipv4_count']);

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_the_request_is_validated()
    {
        $this->api('POST', '/api/v1/servers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_id', 'location', 'os_template', 'network_model', 'resources.cpu_cores']);

        $this->api('POST', '/api/v1/servers', $this->payload([
            'hostname' => 'not a hostname',
            'network_model' => 'nat',
            'ssh_keys' => ['ssh-dss AAAAB3NzaC1kc3MAAACBAP'],
            'root_password' => 'short',
            'resources' => ['cpu_cores' => 0, 'memory_mib' => 64],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hostname', 'network_model', 'ssh_keys.0', 'root_password', 'resources.cpu_cores', 'resources.memory_mib']);

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_the_package_must_allow_the_network_model_and_a_server_needs_an_address()
    {
        $this->package->forceFill(['network_models' => [NetworkModel::Routed]])->save();

        $this->api('POST', '/api/v1/servers', $this->payload(['network_model' => 'bridged']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['network_model' => 'This package does not allow the selected network model.']);

        $this->api('POST', '/api/v1/servers', $this->payload(['resources' => ['ipv4_count' => 0]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resources.ipv4_count']);
    }

    public function test_an_external_id_can_only_be_used_once()
    {
        $this->api('POST', '/api/v1/servers', $this->payload())->assertAccepted();

        $this->api('POST', '/api/v1/servers', $this->payload(['hostname' => 'web2.example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_id']);

        $this->assertDatabaseCount('servers', 1);
    }

    public function test_a_service_can_get_a_new_server_once_its_old_one_is_terminated()
    {
        $this->api('POST', '/api/v1/servers', $this->payload())->assertAccepted();
        $old = Server::query()->sole();

        $this->api('DELETE', "/api/v1/servers/{$old->uuid}")->assertAccepted();
        $this->assertSame(ServerStatus::Terminated, $old->refresh()->status);

        $this->api('POST', '/api/v1/servers', $this->payload())->assertAccepted();
        $this->assertSame(2, Server::query()->where('external_id', 'whmcs-2001')->count());

        // The database refuses a second live server for the service too.
        $this->expectException(UniqueConstraintViolationException::class);
        $copy = Server::query()->where('status', '!=', ServerStatus::Terminated)->sole()->replicate(['uuid', 'live_external_id']);
        $copy->forceFill(['vmid' => 999])->save();
    }

    public function test_placement_problems_are_reported_without_a_server()
    {
        $this->node->forceFill(['is_maintenance' => true])->save();

        $this->api('POST', '/api/v1/servers', $this->payload())
            ->assertUnprocessable()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('errors');

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_a_replayed_idempotency_key_returns_the_first_answer_without_a_second_server()
    {
        $headers = ['Idempotency-Key' => 'order-2001-create'];

        $first = $this->api('POST', '/api/v1/servers', $this->payload(), headers: $headers)->assertAccepted();
        $replay = $this->api('POST', '/api/v1/servers', $this->payload(), headers: $headers)
            ->assertAccepted()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('servers', 1);
        $this->assertSame(1, IdempotencyKey::query()->count());
    }

    public function test_an_idempotency_key_cannot_be_reused_for_another_request()
    {
        $headers = ['Idempotency-Key' => 'order-2001-create'];
        $this->api('POST', '/api/v1/servers', $this->payload(), headers: $headers)->assertAccepted();

        $this->api('POST', '/api/v1/servers', $this->payload(['hostname' => 'other.example.com']), headers: $headers)
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This Idempotency-Key was already used for a different request.']);
    }

    public function test_idempotency_keys_belong_to_one_token()
    {
        $headers = ['Idempotency-Key' => 'shared-key'];
        $this->api('POST', '/api/v1/servers', $this->payload(), headers: $headers)->assertAccepted();

        [, $other] = $this->issueToken();
        // Another token's key does not replay the first answer: the request is handled (and refused as a duplicate).
        $this->api('POST', '/api/v1/servers', $this->payload(), $other, $headers)
            ->assertUnprocessable()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertJsonValidationErrors(['external_id']);
    }

    public function test_a_key_whose_first_request_is_still_running_is_refused()
    {
        $hash = hash('sha256', "POST\napi/v1/servers\n".json_encode($this->payload()));
        $record = new IdempotencyKey;
        $record->forceFill(['api_token_id' => $this->token->id, 'key' => 'in-flight', 'request_hash' => $hash])->save();

        $this->api('POST', '/api/v1/servers', $this->payload(), headers: ['Idempotency-Key' => 'in-flight'])
            ->assertConflict()
            ->assertExactJson(['message' => 'A request with this Idempotency-Key is still being processed.']);
    }

    public function test_invalid_idempotency_keys_are_refused()
    {
        $this->api('POST', '/api/v1/servers', $this->payload(), headers: ['Idempotency-Key' => str_repeat('k', 256)])->assertUnprocessable();
        $this->api('POST', '/api/v1/servers', $this->payload(), headers: ['Idempotency-Key' => 'with space'])->assertUnprocessable();
        $this->assertDatabaseCount('servers', 0);
    }

    public function test_a_failed_create_task_is_an_answer_that_is_replayed()
    {
        $this->proxmox->failClone = 'storage is full';
        $headers = ['Idempotency-Key' => 'failed-clone'];

        $this->api('POST', '/api/v1/servers', $this->payload(), headers: $headers)->assertAccepted()->assertJsonPath('data.status', 'failed');
        $this->api('POST', '/api/v1/servers', $this->payload(), headers: $headers)->assertAccepted()->assertHeader('Idempotent-Replayed', 'true');

        // The billing system sees the failed task; the admin retries it in the panel.
        $this->assertSame(TaskStatus::Failed, Task::query()->sole()->status);
    }

    public function test_server_errors_are_not_stored_so_the_request_can_be_retried()
    {
        $attempts = 0;
        Route::middleware([AuthenticateApiToken::class, 'api.idempotent'])->post('api/v1/test-flaky', function () use (&$attempts) {
            return ++$attempts === 1 ? response()->json(['message' => 'Server Error'], 500) : response()->json(['data' => 'ok'], 201);
        });
        $headers = ['Idempotency-Key' => 'retry-me'];

        $this->api('POST', '/api/v1/test-flaky', headers: $headers)->assertServerError();
        $this->assertSame(0, IdempotencyKey::query()->count());

        $this->api('POST', '/api/v1/test-flaky', headers: $headers)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->api('POST', '/api/v1/test-flaky', headers: $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(2, $attempts);
    }

    public function test_stored_answers_are_pruned_after_a_day()
    {
        $this->api('POST', '/api/v1/servers', $this->payload(), headers: ['Idempotency-Key' => 'old'])->assertAccepted();

        $this->travel(IdempotencyKey::KEEP_HOURS + 1)->hours();
        $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();

        $this->assertSame(0, IdempotencyKey::query()->count());
    }
}
