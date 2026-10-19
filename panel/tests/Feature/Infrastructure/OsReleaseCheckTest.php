<?php

namespace Tests\Feature\Infrastructure;

use App\Domain\Infrastructure\OsRelease;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Comparing an OS template with /etc/os-release of one of its servers
 * (docs/adr/0024 §8): read through the guest agent, nothing saved.
 */
class OsReleaseCheckTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->actingAs(User::factory()->withTwoFactor()->create());
    }

    private function osRelease(string $content, string $path = '/etc/os-release'): void
    {
        $this->proxmox->guestFiles[$this->server->vmid][$path] = $content;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function check(array $query = []): TestResponse
    {
        return $this->getJson(route('admin.os-templates.os-release', ['osTemplate' => $this->osTemplate, ...$query]));
    }

    public function test_a_matching_template_has_no_differences()
    {
        $this->osRelease("PRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\nNAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nID=ubuntu\nID_LIKE=debian\n");

        $this->check()
            ->assertOk()
            ->assertJsonPath('server', $this->server->uuid)
            ->assertJsonPath('servers.0.value', $this->server->uuid)
            ->assertJsonPath('os_release', ['id' => 'ubuntu', 'id_like' => ['debian'], 'version_id' => '24.04', 'pretty_name' => 'Ubuntu 24.04.1 LTS'])
            ->assertJsonPath('differences', []);
    }

    public function test_differences_are_listed_and_nothing_is_saved()
    {
        $this->osRelease("ID=debian\nVERSION_ID=\"12\"\nPRETTY_NAME=\"Debian GNU/Linux 12 (bookworm)\"\n");

        $this->check()
            ->assertOk()
            ->assertJsonPath('suggestion', ['distribution' => 'debian', 'version' => '12', 'family' => 'debian', 'compatible_with' => null, 'compatible_version' => null])
            ->assertJsonPath('differences', [
                ['field' => 'distribution', 'template' => 'ubuntu', 'server' => 'debian'],
                ['field' => 'version', 'template' => '24.04', 'server' => '12'],
            ]);

        $this->assertSame(['ubuntu', '24.04'], [$this->osTemplate->refresh()->distribution, $this->osTemplate->version]);
    }

    public function test_a_point_release_in_the_template_still_matches()
    {
        $this->osTemplate->forceFill(['distribution' => 'debian', 'version' => '12.7'])->save();
        $this->osRelease("ID=debian\nVERSION_ID=\"12\"\n");

        $this->check()->assertOk()->assertJsonPath('differences', []);
    }

    public function test_a_distribution_with_its_own_versions_needs_the_one_it_is_compatible_with()
    {
        $this->osTemplate->forceFill(['distribution' => 'debian', 'version' => '12'])->save();
        $this->osRelease("ID=pardus\nID_LIKE=debian\nVERSION_ID=\"23.4\"\n", '/usr/lib/os-release');

        $this->check()
            ->assertOk()
            ->assertJsonPath('suggestion', ['distribution' => 'pardus', 'version' => '23.4', 'family' => 'debian', 'compatible_with' => 'debian', 'compatible_version' => null])
            ->assertJsonPath('differences.0', ['field' => 'distribution', 'template' => 'debian', 'server' => 'pardus'])
            ->assertJsonPath('differences.2', ['field' => 'compatible_with', 'template' => null, 'server' => 'debian']);
    }

    public function test_rhel_gets_almalinux_of_the_same_version()
    {
        $this->osTemplate->forceFill(['distribution' => 'almalinux', 'version' => '9.6'])->save();
        $this->osRelease("NAME=\"Red Hat Enterprise Linux\"\nID=\"rhel\"\nID_LIKE=\"fedora\"\nVERSION_ID=\"9.6\"\n");

        $this->check()
            ->assertOk()
            ->assertJsonPath('suggestion', ['distribution' => 'rhel', 'version' => '9.6', 'family' => 'rhel', 'compatible_with' => 'almalinux', 'compatible_version' => '9.6']);
    }

    public function test_an_unknown_distribution_takes_its_family_from_id_like()
    {
        $this->osRelease("ID=zorin\nID_LIKE=\"ubuntu debian\"\nVERSION_ID=\"17\"\n");

        $this->check()
            ->assertOk()
            ->assertJsonPath('suggestion.family', 'debian')
            ->assertJsonPath('suggestion.compatible_with', null);
    }

    public function test_an_agent_that_does_not_answer_is_reported()
    {
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $this->check()
            ->assertStatus(422)
            ->assertJsonPath('server', $this->server->uuid)
            ->assertJsonPath('message', "Could not read /etc/os-release of {$this->server->hostname}: its guest agent does not answer, or the file is missing.");
    }

    public function test_only_running_servers_of_the_template_are_used()
    {
        $this->server->forceFill(['status' => 'suspended'])->save();

        $this->check()->assertStatus(422)->assertJsonPath('no_server', true)->assertJsonMissingPath('servers');
        $this->check(['server' => $this->server->uuid])->assertStatus(422);
    }

    public function test_windows_templates_are_not_checked()
    {
        $this->osTemplate->forceFill(['distribution' => 'windows', 'version' => '2022'])->save();

        $this->check()->assertStatus(422);
    }

    public function test_os_release_values_are_read_like_the_shell_does()
    {
        $release = OsRelease::parse("# comment\nID='opensuse-leap'\nID_LIKE=\"suse opensuse\"\nPRETTY_NAME=\"Say \\\"hi\\\"\"\nVERSION_ID=15.6\n");

        $this->assertSame(['id' => 'opensuse-leap', 'id_like' => ['suse', 'opensuse'], 'version_id' => '15.6', 'pretty_name' => 'Say "hi"'], $release->toArray());
        $this->assertSame('linux', OsRelease::parse('')->id, 'os-release(5): ID defaults to linux.');
    }
}
