<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Settings\PanelSettings;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Support\Ssh\PublicKey;
use Tests\Feature\Servers\SshKeysTest;
use Tests\Unit\PublicKeyTest;

/**
 * The SSH keys of a server through the billing API (docs/adr/0049).
 */
class SshKeyApiTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->provisionedServer();
        $this->server->forceFill(['ssh_keys' => PublicKeyTest::ED25519])->save();
    }

    private function url(): string
    {
        return "/api/v1/servers/{$this->server->uuid}/ssh-keys";
    }

    public function test_the_keys_are_listed()
    {
        $this->api('GET', $this->url())
            ->assertOk()
            ->assertExactJson(['data' => [
                'ssh_keys' => [[
                    'type' => 'ssh-ed25519',
                    'fingerprint' => PublicKey::parse(PublicKeyTest::ED25519)->fingerprint(),
                    'comment' => 'test@example.com',
                    'public_key' => PublicKeyTest::ED25519,
                ]],
                'max_keys' => 20,
            ]]);
    }

    public function test_the_whole_list_is_replaced_by_a_task()
    {
        $this->api('PUT', $this->url(), ['ssh_keys' => [PublicKeyTest::RSA]])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'change_ssh_keys')
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertSame(PublicKeyTest::RSA, $this->server->refresh()->ssh_keys);
        $this->assertSame([PublicKeyTest::ED25519], Task::query()->latest('id')->first()->input['removed_ssh_keys']);

        $log = AuditLog::query()->where('action', 'server.ssh_keys_changed')->sole();
        $this->assertSame($this->token->id, $log->api_token_id);

        $this->api('GET', $this->url())->assertJsonPath('data.ssh_keys.0.public_key', PublicKeyTest::RSA);
    }

    public function test_an_empty_list_removes_every_key()
    {
        $this->api('PUT', $this->url(), ['ssh_keys' => []])->assertAccepted();

        $this->assertNull($this->server->refresh()->ssh_keys);
        $this->api('GET', $this->url())->assertJsonPath('data.ssh_keys', []);
    }

    public function test_a_reinstall_keeps_the_keys_unless_it_sends_its_own()
    {
        $template = $this->server->osTemplate->uuid;

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/reinstall", ['os_template' => $template])->assertAccepted();
        $this->assertSame(PublicKeyTest::ED25519, $this->server->refresh()->ssh_keys);

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/reinstall", ['os_template' => $template, 'ssh_keys' => [PublicKeyTest::RSA]])->assertAccepted();
        $this->assertSame(PublicKeyTest::RSA, $this->server->refresh()->ssh_keys);

        $this->api('POST', "/api/v1/servers/{$this->server->uuid}/reinstall", ['os_template' => $template, 'ssh_keys' => []])->assertAccepted();
        $this->assertNull($this->server->refresh()->ssh_keys);
    }

    public function test_invalid_keys_and_missing_lists_are_refused()
    {
        $this->api('PUT', $this->url(), [])->assertUnprocessable()->assertJsonValidationErrors('ssh_keys');
        $this->api('PUT', $this->url(), ['ssh_keys' => ['not a key']])->assertUnprocessable()->assertJsonValidationErrors('ssh_keys.0');
        $this->api('PUT', $this->url(), ['ssh_keys' => SshKeysTest::distinctKeys(21)])->assertUnprocessable();

        $this->assertSame(PublicKeyTest::ED25519, $this->server->refresh()->ssh_keys);
    }

    public function test_the_limit_of_panel_settings_applies_to_new_servers_too()
    {
        PanelSettings::save(['ssh_keys_max' => 2]);

        $this->api('GET', $this->url())->assertJsonPath('data.max_keys', 2);
        $this->api('PUT', $this->url(), ['ssh_keys' => [PublicKeyTest::ED25519, PublicKeyTest::RSA."\n".PublicKeyTest::ECDSA]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssh_keys' => 'A server can have at most 2 SSH keys.']);
        $this->api('POST', '/api/v1/servers', [
            'external_id' => 'whmcs-2002',
            'package' => $this->package->uuid,
            'location' => $this->location->uuid,
            'os_template' => $this->osTemplate->uuid,
            'hostname' => 'web2.example.com',
            'ssh_keys' => [PublicKeyTest::ED25519, PublicKeyTest::RSA, PublicKeyTest::ECDSA],
        ])->assertUnprocessable()->assertJsonValidationErrors('ssh_keys');
    }

    public function test_only_active_servers_without_a_running_task()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();
        $this->api('PUT', $this->url(), ['ssh_keys' => []])->assertConflict();

        $this->server->forceFill(['status' => ServerStatus::Active])->save();
        $this->server->tasks()->latest('id')->first()->forceFill(['status' => TaskStatus::Running])->save();
        $this->api('PUT', $this->url(), ['ssh_keys' => []])->assertConflict();

        $this->assertSame(PublicKeyTest::ED25519, $this->server->refresh()->ssh_keys);
    }
}
