<?php

namespace Tests\Feature\Provisioning;

use App\Enums\CloneMode;
use App\Enums\ServerStatus;
use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Models\Storage;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Linked clones per OS template, with a full clone whenever one cannot be
 * made (docs/adr/0027).
 */
class LinkedCloneTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
    }

    public function test_full_mode_clones_fully_onto_the_chosen_storage()
    {
        $server = app(ServerCreator::class)->create($this->order());

        $clone = $this->cloneRequest();
        $this->assertSame('1', (string) $clone['full']);
        $this->assertSame('local-lvm', $clone['storage']);
        $this->assertNull($this->cloneStep($server)['message']);
        $this->assertSame('local-lvm:vm-100-disk-0,discard=on,size=40960M', $this->proxmox->vms[100]['config']['scsi0']);
    }

    public function test_linked_mode_makes_a_linked_clone_on_the_templates_storage()
    {
        $this->osTemplate->update(['clone_mode' => CloneMode::Linked]);

        $server = app(ServerCreator::class)->create($this->order());

        $clone = $this->cloneRequest();
        $this->assertSame('0', (string) $clone['full']);
        $this->assertArrayNotHasKey('storage', $clone, 'Proxmox takes storage for full clones only.');
        $this->assertArrayNotHasKey('target', $clone);
        $this->assertSame('Linked clone of template 9000.', $this->cloneStep($server)['message']);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        // The disk still grows to the package's size.
        $this->assertSame('local-lvm:base-9000-disk-0/vm-100-disk-0,discard=on,size=40960M', $this->proxmox->vms[100]['config']['scsi0']);
    }

    public function test_a_template_disk_on_another_storage_falls_back_to_a_full_clone()
    {
        $this->osTemplate->update(['clone_mode' => CloneMode::Linked]);
        $this->setTemplateDisk('fast-ssd:base-9000-disk-0,size=3584M');

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame('1', (string) $this->cloneRequest()['full']);
        $this->assertSame("Full clone: The server's storage local-lvm is not the template's storage fast-ssd.", $this->cloneStep($server)['message']);
    }

    public function test_a_storage_type_without_linked_clones_falls_back_to_a_full_clone()
    {
        $this->osTemplate->update(['clone_mode' => CloneMode::Linked]);
        $this->storage->forceFill(['type' => 'lvm'])->save();

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame('1', (string) $this->cloneRequest()['full']);
        $this->assertSame('Full clone: Storage local-lvm (lvm) does not support linked clones.', $this->cloneStep($server)['message']);
    }

    public function test_raw_disks_on_a_directory_storage_fall_back_to_a_full_clone()
    {
        $this->osTemplate->update(['clone_mode' => CloneMode::Linked]);
        $this->storage->forceFill(['type' => 'dir'])->save();
        $this->setTemplateDisk('local-lvm:9000/base-9000-disk-0.raw,size=3584M');

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame('1', (string) $this->cloneRequest()['full']);
        $this->assertSame('Full clone: Storage local-lvm (dir) links only qcow2 disks; local-lvm:9000/base-9000-disk-0.raw is not one.', $this->cloneStep($server)['message']);
    }

    public function test_qcow2_disks_on_a_directory_storage_are_linked()
    {
        $this->osTemplate->update(['clone_mode' => CloneMode::Linked]);
        $this->storage->forceFill(['type' => 'nfs'])->save();
        $this->setTemplateDisk('local-lvm:9000/base-9000-disk-0.qcow2,size=3584M');

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame('0', (string) $this->cloneRequest()['full']);
        $this->assertSame('Linked clone of template 9000.', $this->cloneStep($server)['message']);
    }

    public function test_a_template_on_another_node_with_local_storage_falls_back_to_a_full_clone()
    {
        $this->osTemplate->update(['clone_mode' => CloneMode::Linked]);
        $this->moveTemplateToNewNode();

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame('pve1', $server->node->name);
        $this->assertSame('POST /nodes/pve2/qemu/9000/clone', $this->proxmox->writePaths()[0]);
        $clone = $this->cloneRequest();
        $this->assertSame('1', (string) $clone['full']);
        $this->assertSame('pve1', $clone['target']);
        $this->assertSame('Full clone: Template 9000 is on node pve2 and its storage local-lvm is not shared.', $this->cloneStep($server)['message']);
    }

    public function test_a_template_on_another_node_with_shared_storage_is_linked_across_nodes()
    {
        $this->osTemplate->update(['clone_mode' => CloneMode::Linked]);
        $this->moveTemplateToNewNode();
        Storage::query()->update(['type' => 'rbd', 'is_shared' => true]);

        $server = app(ServerCreator::class)->create($this->order());

        $clone = $this->cloneRequest();
        $this->assertSame('0', (string) $clone['full']);
        $this->assertSame('pve1', $clone['target']);
        $this->assertSame('Linked clone of template 9000.', $this->cloneStep($server)['message']);
        $this->assertSame('pve1', $this->proxmox->vms[100]['node']);
    }

    public function test_a_refused_linked_clone_is_retried_as_a_full_clone()
    {
        $this->osTemplate->update(['clone_mode' => CloneMode::Linked]);
        $this->proxmox->refuseLinkedClone = 'linked clone feature is not supported';

        $server = app(ServerCreator::class)->create($this->order());

        $clones = array_values(array_filter($this->proxmox->writes, fn (array $write) => str_ends_with($write['path'], '/clone')));
        $this->assertSame(['0', '1'], array_map(fn (array $write) => (string) $write['data']['full'], $clones));
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $this->assertSame('Full clone: Proxmox refused a linked clone: linked clone feature is not supported', $this->cloneStep($server)['message']);
    }

    public function test_the_clone_mode_can_be_switched_on_the_template_form()
    {
        $admin = User::factory()->withTwoFactor()->create();
        $form = [
            'name' => $this->osTemplate->name,
            'operating_system_version_id' => $this->osTemplate->operating_system_version_id,
            'is_active' => true,
            'proxmox_template_ids' => [],
        ];

        $this->actingAs($admin)->put(route('admin.os-templates.update', $this->osTemplate), [...$form, 'clone_mode' => 'linked'])
            ->assertSessionHasNoErrors();
        $this->assertSame(CloneMode::Linked, $this->osTemplate->refresh()->clone_mode);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'os_template.clone_mode_changed',
            'user_id' => $admin->id,
            'subject_id' => $this->osTemplate->id,
        ]);

        $this->actingAs($admin)->put(route('admin.os-templates.update', $this->osTemplate), [...$form, 'clone_mode' => 'copy'])
            ->assertSessionHasErrors('clone_mode');

        // Saving without a change writes no audit entry.
        $this->actingAs($admin)->put(route('admin.os-templates.update', $this->osTemplate), [...$form, 'clone_mode' => 'linked']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function cloneRequest(): array
    {
        $clones = array_values(array_filter($this->proxmox->writes, fn (array $write) => str_ends_with($write['path'], '/clone')));
        $this->assertCount(1, $clones);

        return $clones[0]['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function cloneStep(Server $server): array
    {
        return collect($server->tasks()->sole()->steps)->firstWhere('key', 'clone');
    }

    private function setTemplateDisk(string $value): void
    {
        $this->proxmox->vms[9000]['config']['scsi0'] = $value;
    }

    /** Template 9000 lives on a smaller node pve2 only; servers go to pve1. */
    private function moveTemplateToNewNode(): void
    {
        $other = $this->addNode('pve2', memoryGib: 8);
        ProxmoxTemplate::query()->where('vmid', 9000)->update(['node_id' => $other->id]);
        $this->proxmox->vms[9000]['node'] = 'pve2';
    }
}
