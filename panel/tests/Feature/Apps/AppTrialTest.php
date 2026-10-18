<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppNotEligible;
use App\Domain\Apps\AppTrials;
use App\Domain\Apps\AppUpgrades;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\RecipeEditor;
use App\Domain\Apps\RecipeSynchronizer;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRecipeVersionStatus;
use App\Enums\TaskType;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\BillingSystem;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;
use Tests\Unit\PublicKeyTest;

/**
 * Trial runs of app drafts on test servers (docs/adr/0018 §7): a frozen
 * copy of the draft is installed, flagged as a trial, never offered or
 * shown to billing systems.
 */
class AppTrialTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    private AppRecipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
        $this->actingAs($this->admin);
        app(RecipeSynchronizer::class)->sync();

        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->sole();
        $this->recipe = app(RecipeEditor::class)->copy($wireguard->current(), 'my-vpn', 'My VPN', $this->admin, null);
    }

    private function markTest(?Server $server = null): void
    {
        $this->put(route('admin.servers.test', $server ?? $this->server), ['is_test' => true])->assertSessionHasNoErrors();
    }

    private function tryDraft(array $inputs = []): AppInstallation
    {
        $this->post(route('admin.apps.trial', $this->recipe), ['server' => $this->server->uuid, 'inputs' => $inputs])->assertSessionHasNoErrors();

        return AppInstallation::query()->latest('id')->firstOrFail();
    }

    /**
     * Save the draft with a changed install script.
     */
    private function changeDraft(string $line): void
    {
        $draft = $this->recipe->draft();
        $files = $draft->files;
        $files['install.sh'] .= "\n".$line."\n";

        $this->put(route('admin.apps.draft.save', $this->recipe), ['yaml' => Yaml::dump($draft->definition, 6, 4), 'files' => $files])->assertSessionHasNoErrors();
    }

    private function finish(AppInstallation $installation, string $status = 'installed'): AppInstallation
    {
        $this->proxmox->guestFiles[$this->server->vmid][$installation->guestDirectory().'/status.json'] = json_encode(['status' => $status, 'progress' => 100, 'step' => 'Done', 'error' => $status === 'failed' ? 'It broke.' : null], JSON_THROW_ON_ERROR);
        app(InstallationPoller::class)->poll($installation->refresh());

        return $installation->refresh();
    }

    public function test_a_server_is_marked_as_a_test_server_audited_but_never_one_of_a_billing_system()
    {
        $this->markTest();

        $this->assertTrue($this->server->refresh()->is_test);
        $this->assertSame(1, AuditLog::query()->where('action', 'server.test_marked')->count());

        $billed = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com', 'billingSystem' => BillingSystem::factory()->create(), 'externalId' => 'svc-1']))->refresh();

        $this->put(route('admin.servers.test', $billed), ['is_test' => true])->assertSessionHasErrors('is_test');
        $this->assertFalse($billed->refresh()->is_test);

        $this->put(route('admin.servers.test', $this->server), ['is_test' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->server->refresh()->is_test);
        $this->assertSame(1, AuditLog::query()->where('action', 'server.test_unmarked')->count());
    }

    public function test_the_draft_is_installed_as_a_frozen_trial_copy()
    {
        $this->markTest();
        $draft = $this->recipe->draft();

        $installation = $this->tryDraft(['port' => 51900]);
        $trial = $installation->version;

        $this->assertTrue($installation->is_trial);
        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertSame(AppRecipeVersionStatus::Trial, $trial->status);
        $this->assertSame('1.0.0-trial.1', $trial->version);
        $this->assertSame($draft->id, $trial->trial_of_id);
        $this->assertSame($draft->files, $trial->files);
        $this->assertSame($draft->files['install.sh'], $this->proxmox->guestFiles[$this->server->vmid][$installation->guestDirectory().'/install.sh']);
        $this->assertSame(['app' => 'my-vpn', 'version' => '1.0.0-trial.1', 'installation' => $installation->uuid], AuditLog::query()->where('action', 'app.trial_requested')->sole()->properties);

        // Saving the draft later never changes what the trial installed.
        $this->changeDraft('echo changed');
        $this->assertStringNotContainsString('echo changed', $trial->refresh()->files['install.sh']);
        $this->assertSame(AppRecipeVersionStatus::Draft, $this->recipe->draft()?->status);
        $this->assertNull($this->recipe->refresh()->latest_version_id, 'A trial publishes nothing.');
    }

    public function test_an_unchanged_draft_reuses_its_trial_copy_and_a_changed_one_gets_the_next()
    {
        $trials = app(AppTrials::class);
        $first = $trials->freeze($this->recipe->draft());

        $this->assertTrue($first->is($trials->freeze($this->recipe->draft())));

        $this->changeDraft('echo changed');
        $second = $trials->freeze($this->recipe->draft());

        $this->assertSame('1.0.0-trial.2', $second->version);
        $this->assertSame(2, $this->recipe->versions()->where('status', AppRecipeVersionStatus::Trial)->count());
    }

    public function test_drafts_are_only_tried_on_test_servers_and_when_they_validate()
    {
        $this->post(route('admin.apps.trial', $this->recipe), ['server' => $this->server->uuid])->assertSessionHasErrors('server');

        try {
            app(AppInstaller::class)->installTrial($this->server, app(AppTrials::class)->freeze($this->recipe->draft()), [], $this->admin, null);
            $this->fail('A server that is not a test server took a trial.');
        } catch (AppNotEligible $e) {
            $this->assertSame('Drafts can only be tried on test servers.', $e->getMessage());
        }

        $this->markTest();
        $draft = $this->recipe->draft();
        $definition = $draft->definition;
        $definition['category'] = 'not-a-category';
        $this->put(route('admin.apps.draft.save', $this->recipe), ['yaml' => Yaml::dump($definition, 6, 4), 'files' => $draft->files]);

        $this->post(route('admin.apps.trial', $this->recipe), ['server' => $this->server->uuid])->assertSessionHasErrors('app');
        $this->assertSame(0, AppInstallation::query()->count());
    }

    public function test_an_inactive_app_can_be_tried_and_its_failed_trial_retried()
    {
        $this->markTest();
        $this->recipe->forceFill(['is_active' => false])->save();

        $installation = $this->finish($this->tryDraft(), 'failed');
        $this->assertSame(AppInstallationStatus::Failed, $installation->status);

        $this->post(route('admin.app-installations.retry', $installation))->assertSessionHasNoErrors();

        $this->assertSame(AppInstallationStatus::Installing, $installation->refresh()->status);
        $this->assertSame(2, $installation->attempts);
    }

    public function test_the_editor_lists_trials_and_test_servers_and_hides_trial_copies_from_the_history()
    {
        $this->markTest();
        $this->finish($this->tryDraft());

        $this->get(route('admin.apps.edit', $this->recipe))->assertInertia(fn (Assert $page) => $page
            ->has('trials', 1)
            ->where('trials.0.version', '1.0.0-trial.1')
            ->where('trials.0.status', 'installed')
            ->where('trials.0.current', true)
            ->where('trials.0.server.hostname', 'web1.example.com')
            ->where('testServers', [['uuid' => $this->server->uuid, 'hostname' => 'web1.example.com', 'problem' => null]])
            ->where('trialInputs.0.key', 'port')
            ->where('versions', fn ($versions) => collect($versions)->pluck('status')->all() === ['draft']));

        $this->changeDraft('echo changed');

        $this->get(route('admin.apps.edit', $this->recipe))->assertInertia(fn (Assert $page) => $page->where('trials.0.current', false));
    }

    public function test_a_test_server_is_reset_after_trials_by_reinstalling_its_template_with_its_keys()
    {
        $this->markTest();
        $this->server->forceFill(['ssh_keys' => PublicKeyTest::ED25519])->save();
        $installation = $this->finish($this->tryDraft());
        $template = $this->server->os_template_id;
        $password = $this->server->refresh()->root_password;

        $this->post(route('admin.servers.trial-reset', $this->server))->assertSessionHasNoErrors();

        $server = $this->server->refresh();
        $this->assertSame(TaskType::Reinstall, $server->tasks()->latest('id')->firstOrFail()->type);
        $this->assertSame($template, $server->os_template_id);
        $this->assertSame(PublicKeyTest::ED25519, $server->ssh_keys);
        $this->assertNotSame($password, $server->root_password, 'The new system gets a new root password.');
        $this->assertSame(AppInstallationStatus::Removed, $installation->refresh()->status);
        $this->assertTrue($server->is_test, 'It stays a test server.');
        $this->assertSame(['hostname' => 'web1.example.com', 'os_template' => $server->osTemplate?->name], AuditLog::query()->where('action', 'server.trial_reset')->sole()->properties);
        $this->assertSame(1, AuditLog::query()->where('action', 'server.reinstall')->count());
    }

    public function test_only_an_active_test_server_is_reset()
    {
        $this->post(route('admin.servers.trial-reset', $this->server))->assertSessionHas('inertia.flash_data.toast.message', 'Only a test server can be reset after a trial.');

        $this->assertFalse($this->server->tasks()->where('type', TaskType::Reinstall)->exists());
        $this->assertSame(0, AuditLog::query()->where('action', 'server.trial_reset')->count());
    }

    public function test_the_server_page_flags_trials_and_test_servers()
    {
        $this->markTest();
        $this->tryDraft();

        $this->get(route('admin.servers.show', $this->server))->assertInertia(fn (Assert $page) => $page
            ->where('server.is_test', true)
            ->where('apps.0.is_trial', true)
            ->where('apps.0.version', '1.0.0-trial.1'));
    }

    public function test_trials_are_never_upgraded_and_do_not_keep_an_app_from_being_deleted()
    {
        $this->markTest();
        $installation = $this->finish($this->tryDraft());

        $this->post(route('admin.apps.publish', $this->recipe), ['bump' => 'patch'])->assertSessionHasNoErrors();
        $this->assertNull(app(AppUpgrades::class)->target($installation->refresh()));

        $this->delete(route('admin.apps.destroy', $this->recipe))->assertSessionHasErrors('app');

        $installation->forceFill(['status' => AppInstallationStatus::Removed])->save();
        $this->delete(route('admin.apps.destroy', $this->recipe))->assertSessionHasNoErrors();

        $this->assertNull(AppRecipe::query()->where('slug', 'my-vpn')->first());
        $this->assertSame(0, AppInstallation::query()->count());
    }
}
