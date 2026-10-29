<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TaskType;
use App\Models\Backup;
use App\Models\Cluster;
use App\Models\Server;

/**
 * Backups through the billing API (docs/adr/0051 §7).
 */
class BackupApiTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->package->forceFill(['backup_slots' => 1])->save();
        $this->node->storages()->forceCreate([
            'name' => 'backup-nfs',
            'type' => 'nfs',
            'content' => ['backup'],
            'is_shared' => true,
            'is_available' => true,
            'use_for_backups' => true,
            'total_bytes' => 1000 * 1024 ** 3,
            'used_bytes' => 0,
        ]);
        $this->server = $this->provisionedServer();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/servers/{$this->server->uuid}/backups{$suffix}";
    }

    public function test_a_backup_is_taken_listed_restored_and_deleted()
    {
        $this->api('GET', $this->url())
            ->assertOk()
            ->assertJsonPath('data.backups', [])
            ->assertJsonPath('data.slots', 1)
            ->assertJsonPath('data.unavailable_reason', null);

        $this->api('POST', $this->url(), ['label' => 'Before PHP 8.4'])
            ->assertAccepted()
            ->assertJsonPath('data.type', TaskType::CreateBackup->value);

        $backup = Backup::query()->sole();
        $this->api('GET', $this->url())
            ->assertJsonPath('data.backups.0.id', $backup->uuid)
            ->assertJsonPath('data.backups.0.label', 'Before PHP 8.4')
            ->assertJsonPath('data.backups.0.kind', 'manual')
            ->assertJsonPath('data.backups.0.size_bytes', 1024 ** 3)
            ->assertJsonPath('data.backups.0.os_template', $this->osTemplate->name)
            ->assertJsonPath('data.backups.0.restorable', true)
            ->assertJsonMissingPath('data.backups.0.storage')
            ->assertJsonMissingPath('data.backups.0.volume');
        $this->assertSame($this->token->id, $this->server->tasks()->latest('id')->firstOrFail()->api_token_id);

        $this->api('POST', $this->url("/{$backup->uuid}/restore"))
            ->assertAccepted()
            ->assertJsonPath('data.type', TaskType::RestoreBackup->value);

        $this->api('DELETE', $this->url("/{$backup->uuid}"))
            ->assertAccepted()
            ->assertJsonPath('data.type', TaskType::DeleteBackup->value);
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_full_slots_servers_without_backups_and_unreachable_backups_answer_with_a_code()
    {
        $this->api('POST', $this->url())->assertAccepted();

        $this->api('POST', $this->url())
            ->assertConflict()
            ->assertJsonPath('error', 'backup_slots_full');

        // Moved to another cluster: its backup stays where it was.
        $backup = Backup::query()->sole();
        $backup->forceFill(['cluster_id' => Cluster::factory()->for($this->location)->create()->id])->save();
        $this->api('GET', $this->url())
            ->assertJsonPath('data.backups.0.restorable', false)
            ->assertJsonPath('data.backups.0.unrestorable_reason', 'This backup is on another cluster than the server.');
        $this->api('POST', $this->url("/{$backup->uuid}/restore"))
            ->assertConflict()
            ->assertJsonPath('error', 'backup_not_restorable');

        $this->server->forceFill(['backup_slots' => 0])->save();
        $this->api('GET', $this->url())->assertJsonPath('data.unavailable_reason', 'Backups are not part of this server\'s plan.');
        $this->api('POST', $this->url())
            ->assertUnprocessable()
            ->assertJsonPath('error', 'backups_not_supported');

        $this->server->forceFill(['backup_slots' => 3])->save();
        $this->node->storages()->update(['use_for_backups' => false]);
        $this->api('POST', $this->url())
            ->assertUnprocessable()
            ->assertJsonPath('error', 'no_backup_storage');
    }

    public function test_labels_are_checked_and_backups_of_other_servers_are_not_found()
    {
        $this->api('POST', $this->url(), ['label' => "line\nbreak"])->assertUnprocessable()->assertJsonValidationErrors(['label']);
        $this->api('POST', $this->url(), ['label' => str_repeat('a', 65)])->assertUnprocessable()->assertJsonValidationErrors(['label']);

        $other = $this->provisionedServer('whmcs-1002');
        $this->api('POST', "/api/v1/servers/{$other->uuid}/backups")->assertAccepted();
        $foreign = Backup::query()->where('server_id', $other->id)->sole();

        $this->api('POST', $this->url("/{$foreign->uuid}/restore"))->assertNotFound();
        $this->api('DELETE', $this->url("/{$foreign->uuid}"))->assertNotFound();
    }
}
