<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TaskType;
use App\Models\Server;
use App\Models\Snapshot;

/**
 * Snapshots through the billing API (docs/adr/0051 §7).
 */
class SnapshotApiTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->package->forceFill(['snapshot_slots' => 1])->save();
        $this->server = $this->provisionedServer();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/servers/{$this->server->uuid}/snapshots{$suffix}";
    }

    public function test_a_snapshot_is_taken_listed_rolled_back_and_deleted()
    {
        $this->api('GET', $this->url())
            ->assertOk()
            ->assertJsonPath('data.snapshots', [])
            ->assertJsonPath('data.slots', 1)
            ->assertJsonPath('data.unavailable_reason', null);

        $this->api('POST', $this->url(), ['label' => 'Before PHP 8.4'])
            ->assertAccepted()
            ->assertJsonPath('data.type', TaskType::CreateSnapshot->value);

        $snapshot = Snapshot::query()->sole();
        $this->api('GET', $this->url())
            ->assertJsonPath('data.snapshots.0.id', $snapshot->uuid)
            ->assertJsonPath('data.snapshots.0.label', 'Before PHP 8.4');
        $this->assertSame($this->token->id, $this->server->tasks()->latest('id')->firstOrFail()->api_token_id);

        $this->api('POST', $this->url("/{$snapshot->uuid}/rollback"))
            ->assertAccepted()
            ->assertJsonPath('data.type', TaskType::RollbackSnapshot->value);

        $this->api('DELETE', $this->url("/{$snapshot->uuid}"))
            ->assertAccepted()
            ->assertJsonPath('data.type', TaskType::DeleteSnapshot->value);
        $this->assertSame(0, Snapshot::query()->count());
    }

    public function test_full_slots_and_servers_without_snapshots_answer_with_a_code()
    {
        $this->api('POST', $this->url())->assertAccepted();

        $this->api('POST', $this->url())
            ->assertConflict()
            ->assertJsonPath('error', 'snapshot_slots_full');

        $this->server->forceFill(['snapshot_slots' => 0])->save();
        $this->api('GET', $this->url())->assertJsonPath('data.unavailable_reason', 'Snapshots are not part of this server\'s plan.');
        $this->api('POST', $this->url())
            ->assertUnprocessable()
            ->assertJsonPath('error', 'snapshots_not_supported');
    }

    public function test_labels_are_checked_and_snapshots_of_other_servers_are_not_found()
    {
        $this->api('POST', $this->url(), ['label' => "line\nbreak"])->assertUnprocessable()->assertJsonValidationErrors(['label']);
        $this->api('POST', $this->url(), ['label' => str_repeat('a', 65)])->assertUnprocessable()->assertJsonValidationErrors(['label']);

        $other = $this->provisionedServer('whmcs-1002');
        $this->api('POST', "/api/v1/servers/{$other->uuid}/snapshots")->assertAccepted();
        $foreign = Snapshot::query()->where('server_id', $other->id)->sole();

        $this->api('POST', $this->url("/{$foreign->uuid}/rollback"))->assertNotFound();
        $this->api('DELETE', $this->url("/{$foreign->uuid}"))->assertNotFound();
    }

    public function test_the_slots_come_from_the_package_or_the_resources()
    {
        $this->api('GET', "/api/v1/servers/{$this->server->uuid}")
            ->assertJsonPath('data.resources.snapshot_slots', 1)
            ->assertJsonPath('data.resources.backup_slots', 1);

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/package", ['resources' => ['snapshot_slots' => 5, 'backup_slots' => 0]])->assertAccepted();

        $this->assertSame(5, $this->server->refresh()->snapshot_slots);
        $this->assertSame(0, $this->server->backup_slots);
        $this->api('GET', '/api/v1/packages')->assertJsonPath('data.0.snapshot_slots', 1);
    }
}
