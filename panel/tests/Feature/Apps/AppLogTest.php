<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppLogs;
use App\Domain\Apps\AppRunner;
use App\Domain\Apps\InstallationPoller;
use App\Domain\Apps\LiveAppPolls;
use App\Domain\Apps\RunPoller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AppInstallation;
use App\Models\AppLog;
use App\Models\AppRecipe;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Concerns\OffersApps;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * What app scripts print (docs/adr/0021): copied from run.log through the
 * guest agent while they run and once more when they end, masked, encrypted,
 * one log per install attempt and per run, watched live by administrators.
 */
class AppLogTest extends TestCase
{
    use BuildsInfrastructure, OffersApps, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
        $this->syncRecipes();
        $this->offerApps();
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function install(string $slug = 'wireguard', array $inputs = []): AppInstallation
    {
        return app(AppInstaller::class)->install($this->server, AppRecipe::query()->where('slug', $slug)->firstOrFail(), $inputs, $this->admin, '127.0.0.1')->refresh();
    }

    private function guestFile(string $path, string $content): void
    {
        $this->proxmox->guestFiles[$this->server->vmid][$path] = $content;
    }

    private function printed(AppInstallation $installation, string $log): void
    {
        $this->guestFile($installation->guestDirectory().'/run.log', $log);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function reports(AppInstallation $installation, array $status): void
    {
        $this->guestFile($installation->guestDirectory().'/status.json', json_encode($status, JSON_THROW_ON_ERROR));
    }

    private function poll(AppInstallation $installation): AppInstallation
    {
        app(InstallationPoller::class)->poll($installation->refresh());

        return $installation->refresh();
    }

    private function logOf(AppInstallation $installation): AppLog
    {
        return $installation->logs()->latest('id')->firstOrFail();
    }

    public function test_starting_an_installer_opens_a_log_for_the_attempt()
    {
        $installation = $this->install();
        $log = $this->logOf($installation);

        $this->assertSame(1, $log->attempt);
        $this->assertNull($log->app_run_id);
        $this->assertNull($log->completed_at);
        $this->assertSame($installation->guestDirectory().'/run.log', $log->guestFile());
    }

    public function test_the_poller_copies_complete_lines_while_the_installer_runs()
    {
        $installation = $this->install();
        $this->reports($installation, ['status' => 'installing', 'progress' => 20, 'step' => 'Installing WireGuard']);
        $this->printed($installation, "==> Preparing\nReading package lists...\nhalf a li");

        $log = $this->logOf($this->poll($installation));

        $this->assertSame("==> Preparing\nReading package lists...\n", $log->content);
        $this->assertSame(strlen("==> Preparing\nReading package lists...\n"), $log->offset);
        $this->assertNull($log->completed_at);

        $this->printed($installation, "==> Preparing\nReading package lists...\nhalf a line\nDone\n");
        $log = $this->logOf($this->poll($installation));

        $this->assertSame("==> Preparing\nReading package lists...\nhalf a line\nDone\n", $log->content);
    }

    public function test_the_log_is_finished_when_the_installer_fails_and_kept_encrypted()
    {
        $installation = $this->install();
        $this->printed($installation, "==> Installing\nE: Unable to locate package wireguard");
        $this->reports($installation, ['status' => 'failed', 'progress' => 10, 'step' => 'Installing', 'message' => 'exit code 100']);

        $log = $this->logOf($this->poll($installation));

        $this->assertSame("==> Installing\nE: Unable to locate package wireguard", $log->content, 'The last line is taken once the script ended.');
        $this->assertNotNull($log->completed_at);
        $this->assertStringNotContainsString('Unable to locate', (string) DB::table('app_logs')->where('id', $log->id)->value('content'));
    }

    public function test_secret_inputs_and_outputs_are_masked()
    {
        $installation = $this->install('openvpn', ['admin_password' => 'Owned-1234-abcd']);
        $this->reports($installation, ['status' => 'installing', 'progress' => 20, 'step' => 'Installing']);
        $this->printed($installation, "Using licence Owned-1234-abcd\nAdmin password: Gen3rated-Pass\n");
        $live = $this->logOf($this->poll($installation));

        $this->assertSame('Using licence '.AppLogs::MASK."\nAdmin password: Gen3rated-Pass\n", $live->content, 'Outputs are not known yet.');

        $this->reports($installation, ['status' => 'installed', 'progress' => 100, 'step' => 'Installed']);
        $this->guestFile($installation->guestDirectory().'/outputs.json', json_encode(['username' => 'whmcsadmin', 'admin_password' => 'Gen3rated-Pass'], JSON_THROW_ON_ERROR));
        $log = $this->logOf($this->poll($installation));

        $this->assertSame('Using licence '.AppLogs::MASK."\nAdmin password: ".AppLogs::MASK."\n", $log->content);
        $this->assertNotNull($log->completed_at);
    }

    public function test_a_retry_gets_a_new_log_and_the_old_one_is_kept()
    {
        $installation = $this->install();
        $this->printed($installation, "first attempt\n");
        $this->reports($installation, ['status' => 'failed', 'progress' => 10, 'step' => 'x', 'message' => 'boom']);
        $this->poll($installation);

        $installation = app(AppInstaller::class)->retry($installation, $this->admin, '127.0.0.1')->refresh();

        $this->assertSame([2, 1], $installation->logs()->latest('id')->pluck('attempt')->all());
        $this->assertSame("first attempt\n", $installation->logs()->where('attempt', 1)->sole()->content);
        $this->assertSame(2, app(AppLogs::class)->current($installation)?->attempt);
    }

    public function test_runs_have_their_own_log()
    {
        $installation = $this->install();
        $this->reports($installation, ['status' => 'installed', 'progress' => 100, 'step' => 'Installed']);
        $this->poll($installation);

        $run = app(AppRunner::class)->action($installation->refresh(), 'add_client', ['name' => 'laptop'], $this->admin, '127.0.0.1')->refresh();
        $this->guestFile($run->guestDirectory().'/run.log', "==> Adding laptop\n");
        $this->guestFile($run->guestDirectory().'/status.json', json_encode(['status' => 'succeeded', 'progress' => 100, 'step' => 'Done'], JSON_THROW_ON_ERROR));
        app(RunPoller::class)->poll($run->refresh());

        $log = $run->refresh()->log;
        $this->assertNotNull($log);
        $this->assertSame("==> Adding laptop\n", $log->content);
        $this->assertNotNull($log->completed_at);
        $this->assertSame(1, $installation->logs()->count(), 'The run log is not an install attempt.');
    }

    public function test_terminal_output_is_made_readable()
    {
        $this->assertSame(
            "Progress: 100%\nred text\nbroken \u{FFFD} byte\n",
            AppLogs::clean("Progress: 10%\rProgress: 50%\rProgress: 100%\r\n\e[31mred\e[0m text\nbroken \xC3 byte\n"),
        );
    }

    public function test_a_long_log_keeps_its_start_and_its_newest_output()
    {
        $installation = $this->install();
        $this->reports($installation, ['status' => 'installing', 'progress' => 20, 'step' => 'Installing']);
        $line = str_repeat('x', 99)."\n";
        $output = "FIRST LINE\n".str_repeat($line, (int) (AppLog::MAX_BYTES * 1.5 / 100))."LAST LINE\n";
        $this->printed($installation, $output);

        // One poll reads at most MAX_CHUNKS chunks: keep polling until the whole file is read.
        do {
            $log = $this->logOf($this->poll($installation));
        } while ($log->offset < strlen($output));

        $this->assertLessThanOrEqual(AppLog::MAX_BYTES, strlen((string) $log->content));
        $this->assertStringStartsWith("FIRST LINE\n", (string) $log->content);
        $this->assertStringEndsWith("LAST LINE\n", (string) $log->content);
        $this->assertSame(strlen($output), $log->length);
        $this->assertSame(strlen($output) - strlen((string) $log->content), $log->omitted_bytes);
        $this->assertStringContainsString('left out', AppLogs::render($log));
    }

    public function test_administrators_watch_the_log_live()
    {
        $installation = $this->install();
        $this->reports($installation, ['status' => 'installing', 'progress' => 30, 'step' => 'Installing WireGuard']);
        $this->printed($installation, "one\n");
        $log = $this->logOf($installation);

        $first = $this->actingAs($this->admin)->getJson(route('admin.app-logs.show', $log))
            ->assertOk()
            ->assertJson(['text' => "one\n", 'reset' => true, 'cursor' => 4, 'live' => true, 'progress' => 30, 'step' => 'Installing WireGuard']);

        $this->printed($installation, "one\ntwo\n");
        $this->travel(5)->seconds();

        $this->actingAs($this->admin)->getJson(route('admin.app-logs.show', ['appLog' => $log, 'cursor' => $first->json('cursor')]))
            ->assertOk()
            ->assertJson(['text' => "two\n", 'reset' => false, 'cursor' => 8, 'live' => true]);

        $this->reports($installation, ['status' => 'failed', 'progress' => 30, 'step' => 'Installing WireGuard', 'message' => 'boom']);
        $this->poll($installation);

        $this->actingAs($this->admin)->getJson(route('admin.app-logs.show', ['appLog' => $log, 'cursor' => 8]))
            ->assertOk()
            ->assertJson(['text' => "one\ntwo\n", 'reset' => true, 'live' => false, 'status' => 'failed']);
    }

    public function test_the_guest_is_not_asked_more_often_than_every_few_seconds()
    {
        $installation = $this->install();
        $this->reports($installation, ['status' => 'installing', 'progress' => 30, 'step' => 'x']);
        $this->printed($installation, "one\n");
        $log = $this->logOf($installation);

        $this->actingAs($this->admin)->getJson(route('admin.app-logs.show', $log))->assertOk();
        $reads = $this->proxmox->guestLogReads;
        $this->actingAs($this->admin)->getJson(route('admin.app-logs.show', ['appLog' => $log, 'cursor' => 4]))->assertOk();

        $this->assertSame($reads, $this->proxmox->guestLogReads);
    }

    /**
     * A refresh of the server page, as usePoll asks for it.
     */
    private function refreshServerPage(): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'admin/servers/Show',
            'X-Inertia-Partial-Data' => 'server,tasks,power,agent,apps',
        ])->assertOk();
    }

    public function test_refreshes_of_the_server_page_follow_a_running_installer()
    {
        $installation = $this->install();
        $this->reports($installation, ['status' => 'installing', 'progress' => 40, 'step' => 'Creating keys']);
        $this->printed($installation, "==> Creating keys\n");

        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))->assertOk();
        $this->assertSame(0, $installation->refresh()->progress, 'A full page load does not ask the guest.');

        $page = $this->refreshServerPage();

        $this->assertSame(40, $page->json('props.apps.0.progress'));
        $this->assertSame('Creating keys', $page->json('props.apps.0.step'));
        $this->assertSame("==> Creating keys\n", $this->logOf($installation)->content);

        $this->reports($installation, ['status' => 'failed', 'progress' => 40, 'step' => 'Creating keys', 'message' => 'boom']);
        $this->assertSame('installing', $this->refreshServerPage()->json('props.apps.0.status'), 'Not again within a few seconds.');

        $this->travel(LiveAppPolls::EVERY_SECONDS + 1)->seconds();
        $this->assertSame('failed', $this->refreshServerPage()->json('props.apps.0.status'));
    }

    public function test_refreshes_of_the_server_page_follow_a_running_action()
    {
        $installation = $this->install();
        $this->reports($installation, ['status' => 'installed', 'progress' => 100, 'step' => 'Installed']);
        $this->poll($installation);
        $run = app(AppRunner::class)->action($installation->refresh(), 'add_client', ['name' => 'laptop'], $this->admin, '127.0.0.1')->refresh();
        $this->guestFile($run->guestDirectory().'/status.json', json_encode(['status' => 'running', 'progress' => 50, 'step' => 'Adding laptop'], JSON_THROW_ON_ERROR));

        $this->assertSame('Adding laptop', $this->refreshServerPage()->json('props.apps.0.runs.0.step'));
    }

    public function test_only_administrators_see_logs()
    {
        $log = $this->logOf($this->install());

        $this->getJson(route('admin.app-logs.show', $log))->assertUnauthorized();
    }

    public function test_the_server_page_lists_the_logs_without_their_content()
    {
        $installation = $this->install();
        $this->printed($installation, "secret-free output\n");
        $this->reports($installation, ['status' => 'installing', 'progress' => 30, 'step' => 'x']);
        $this->poll($installation);

        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('apps.0.logs.0.uuid', $this->logOf($installation)->uuid)
                ->where('apps.0.logs.0.attempt', 1)
                ->missing('apps.0.logs.0.content'));
    }

    public function test_logs_are_pruned_after_90_days()
    {
        $old = $this->logOf($this->install());
        $this->travel(AppLog::KEEP_DAYS + 1)->days();

        $this->artisan('model:prune', ['--model' => [AppLog::class]])->assertSuccessful();

        $this->assertModelMissing($old);
    }
}
