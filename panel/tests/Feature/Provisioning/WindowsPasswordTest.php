<?php

namespace Tests\Feature\Provisioning;

use App\Domain\Servers\GuestScripts;
use App\Domain\Servers\PasswordGenerator;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Proxmox writes a Windows VM's password unhashed into cloud-init's user data,
 * where Cloudbase-Init fails on one that is not valid YAML (a first @, %, …).
 * Such passwords go to Proxmox as a temporary one and are set through the
 * guest agent once Windows setup has finished.
 */
class WindowsPasswordTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->proxmox->execHandler = fn (array $command) => $command === GuestScripts::WINDOWS_SETUP_COMMAND
            ? [0, "image=IMAGE_STATE_COMPLETE\r\n", '']
            : [0, '', ''];
        Sleep::fake();
    }

    private function step(Server $server): ?array
    {
        return collect($server->tasks()->sole()->steps)->firstWhere('key', 'set_password');
    }

    public function test_a_password_that_fits_the_user_data_goes_to_cloud_init_as_it_is()
    {
        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame('a-very-long-password', $this->proxmox->vms[$server->vmid]['config']['cipassword']);
        $this->assertSame([], $this->proxmox->guestPasswords[$server->vmid] ?? []);
        $this->assertNull($this->step($server));
    }

    public function test_a_password_that_does_not_fit_is_set_through_the_guest_agent_after_setup()
    {
        $server = app(ServerCreator::class)->create($this->order(['rootPassword' => '@Chosen: in WHMCS']))->refresh();

        $temporary = $this->proxmox->vms[$server->vmid]['config']['cipassword'];
        $this->assertNotSame('@Chosen: in WHMCS', $temporary);
        $this->assertTrue(PasswordGenerator::fitsCloudInit($temporary));
        $this->assertSame('@Chosen: in WHMCS', $this->proxmox->guestPasswords[$server->vmid]['Administrator']);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame('@Chosen: in WHMCS', $server->root_password);
        $this->assertNull($server->tasks()->sole()->secret, 'The temporary password is not kept.');
        $this->assertSame('done', $this->step($server)['status']);
    }

    public function test_the_server_keeps_the_temporary_password_when_the_agent_cannot_set_it()
    {
        $this->proxmox->agentDown[100] = true;
        Sleep::fake(syncWithCarbon: true);

        $server = app(ServerCreator::class)->create($this->order(['rootPassword' => '%chosen-password']))->refresh();

        $temporary = $this->proxmox->vms[$server->vmid]['config']['cipassword'];
        $this->assertSame($temporary, $server->root_password, 'The billing system can fetch the password the server has.');
        $this->assertTrue($server->root_password_unrevealed);
        $this->assertNull($server->tasks()->sole()->secret);
        $this->assertStringStartsWith('Could not set the password through the guest agent', $this->step($server)['message']);
    }

    public function test_linux_servers_always_give_cloud_init_the_password_which_proxmox_hashes()
    {
        $this->osTemplate->forceFill(['distribution' => 'ubuntu'])->save();

        $server = app(ServerCreator::class)->create($this->order(['rootPassword' => '@Chosen: in WHMCS']))->refresh();

        $this->assertSame('@Chosen: in WHMCS', $this->proxmox->vms[$server->vmid]['config']['cipassword']);
        $this->assertNull($this->step($server));
    }
}
