<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppNotEligible;
use App\Domain\Apps\RecipeSynchronizer;
use App\Domain\Servers\ServerReinstaller;
use App\Enums\AppInstallationStatus;
use App\Enums\AppInstallMode;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Apps chosen when a server is ordered or reinstalled (docs/adr/0016 §5):
 * the create and reinstall pipelines start them once the guest agent
 * answers (docs/adr/0004), and a failed start never fails the server.
 */
class OrderedAppTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->admin = User::factory()->create();
        app(RecipeSynchronizer::class)->sync();
    }

    private function app(string $slug): AppRecipe
    {
        return AppRecipe::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function orderWith(string $slug, array $inputs = []): Server
    {
        return app(ServerCreator::class)->create($this->order(['app' => $this->app($slug), 'appInputs' => $inputs]), $this->admin)->refresh();
    }

    private function startCommands(Server $server): int
    {
        return collect($this->proxmox->guestCommands)
            ->filter(fn (array $command) => $command['vmid'] === $server->vmid && $command['command'][0] === 'systemd-run')
            ->count();
    }

    public function test_an_app_ordered_with_the_server_starts_after_the_first_boot()
    {
        $server = $this->orderWith('wireguard', ['port' => 51900]);
        $installation = $server->appInstallations()->sole();
        $task = $server->tasks()->sole();
        $directory = '/var/lib/proxmotion/apps/'.$installation->uuid;

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(TaskType::Create, $task->type);
        $this->assertTrue($task->hasCompleted('app_upload'));
        $this->assertTrue($task->hasCompleted('app_start'));

        $this->assertSame(AppInstallMode::Order, $installation->mode);
        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertSame($task->id, $installation->task_id);
        $this->assertSame($this->admin->id, $installation->user_id);
        $this->assertSame(['port' => 51900], json_decode($this->proxmox->guestFiles[$server->vmid][$directory.'/inputs.json'], true));
        $this->assertSame(1, $this->startCommands($server));
        $this->assertSame('order', AuditLog::query()->where('action', 'app.install_requested')->sole()->properties['mode']);
    }

    public function test_an_ineligible_app_refuses_the_order_before_anything_is_reserved()
    {
        $this->app('wireguard')->forceFill(['is_active' => false])->save();

        try {
            $this->orderWith('wireguard');
            $this->fail('An inactive app was accepted.');
        } catch (AppNotEligible $e) {
            $this->assertSame('This app is not available.', $e->translatedMessage());
        }

        $this->assertSame(0, Server::query()->count());
        $this->assertSame(0, AppInstallation::query()->count());
    }

    public function test_invalid_app_inputs_refuse_the_order()
    {
        try {
            $this->orderWith('openvpn', ['port' => 70000]);
            $this->fail('Invalid inputs were accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['app_inputs.port'], array_keys($e->errors()));
        }

        $this->assertSame(0, Server::query()->count());
    }

    public function test_the_server_becomes_active_when_its_agent_never_answers()
    {
        Sleep::fake(syncWithCarbon: true);
        config(['proxmotion.provisioning.min_vmid' => 500]);
        $this->proxmox->agentDown[500] = true;

        $server = $this->orderWith('wireguard');
        $installation = $server->appInstallations()->sole();

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame(TaskStatus::Succeeded, $server->tasks()->sole()->status);
        $this->assertSame(AppInstallationStatus::Failed, $installation->status);
        $this->assertStringContainsString('guest agent did not answer', (string) $installation->error);
        $this->assertSame(0, $this->startCommands($server));
    }

    public function test_reinstalling_with_an_app_installs_it_on_the_new_system()
    {
        $server = $this->orderWith('wireguard');
        $old = $server->appInstallations()->sole();

        app(ServerReinstaller::class)->request($server, $server->osTemplate, [], $this->admin, '127.0.0.1', $this->app('docker'));

        $new = $server->appInstallations()->where('id', '!=', $old->id)->sole();

        $this->assertSame(AppInstallationStatus::Removed, $old->refresh()->status);
        $this->assertSame('docker', $new->recipe->slug);
        $this->assertSame(AppInstallMode::Order, $new->mode);
        $this->assertSame(AppInstallationStatus::Installing, $new->status);
        $this->assertSame(TaskType::Reinstall, $new->task?->type);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $this->assertSame('docker', AuditLog::query()->where('action', 'server.reinstall')->sole()->properties['app']);
    }

    public function test_reinstalling_without_an_app_drops_one_queued_for_a_system_that_never_booted()
    {
        $server = app(ServerCreator::class)->create($this->order())->refresh();
        $stale = AppInstallation::factory()->create([
            'server_id' => $server->id,
            'app_recipe_id' => $this->app('wireguard')->id,
            'status' => AppInstallationStatus::Queued,
            'mode' => AppInstallMode::Order,
            'attempts' => 0,
        ]);

        app(ServerReinstaller::class)->request($server, $server->osTemplate, [], $this->admin, '127.0.0.1');

        $this->assertSame(AppInstallationStatus::Removed, $stale->refresh()->status);
        $this->assertSame(0, $this->startCommands($server));
    }

    public function test_an_app_that_does_not_fit_the_new_system_refuses_the_reinstall()
    {
        $server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->app('docker')->forceFill(['is_active' => false])->save();

        $this->expectException(AppNotEligible::class);

        try {
            app(ServerReinstaller::class)->request($server, $server->osTemplate, [], $this->admin, '127.0.0.1', $this->app('docker'));
        } finally {
            $this->assertSame(1, $server->tasks()->count(), 'Only the create task.');
        }
    }
}
