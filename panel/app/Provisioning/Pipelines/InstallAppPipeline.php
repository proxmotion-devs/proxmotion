<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppInstallations;
use App\Domain\Apps\RecipeLoader;
use App\Domain\Servers\GuestScriptFailed;
use App\Enums\AppInstallationStatus;
use App\Enums\IpVersion;
use App\Models\AppInstallation;
use App\Models\Server;
use App\Models\Task;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;
use stdClass;
use Throwable;

/**
 * Starts an app installer inside the server (docs/adr/0015): writes the
 * recipe's install.sh, the shared helpers and runner, inputs.json and
 * server.json to /var/lib/proxmotion/apps/<installation>/ through the guest
 * agent, then starts the runner detached with systemd-run. The task ends
 * there; apps:poll follows status.json until the app is installed.
 */
final readonly class InstallAppPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private RecipeLoader $recipes,
        private AppInstallations $installations,
    ) {}

    public function run(Task $task): void
    {
        $uuid = is_string($task->input['installation'] ?? null) ? $task->input['installation'] : '';
        $installation = AppInstallation::query()->with('recipe')->where('uuid', $uuid)->where('server_id', $task->server_id)->first();

        if ($installation === null) {
            throw new GuestScriptFailed('The app installation no longer exists.');
        }

        if ($installation->status !== AppInstallationStatus::Queued) {
            $task->completeStep('app_start', 'The installation was already started.');

            return;
        }

        try {
            $this->start($task, $task->server, $installation);
        } catch (Throwable $e) {
            $this->installations->failed($installation, $e instanceof ProxmoxException ? $e->getMessage() : 'The installer could not be started.');

            throw $e;
        }
    }

    private function start(Task $task, Server $server, AppInstallation $installation): void
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $directory = $installation->guestDirectory();

        $task->startStep('app_upload');

        if (! $agent->ping($server->vmid)) {
            throw new GuestScriptFailed('The guest agent did not answer: is the server running with qemu-guest-agent?');
        }

        $agent->run($server->vmid, ['mkdir', '-p', '-m', '700', $directory]);

        $files = $this->recipes->library() + [
            'install.sh' => $installation->recipe->files['install.sh'] ?? '',
            'inputs.json' => self::json($installation->inputs === [] ? new stdClass : $installation->inputs),
            'server.json' => self::json($this->serverFacts($server)),
            // A fresh status, so a poll before the runner starts never sees an earlier attempt's outcome.
            'status.json' => self::json(['status' => 'installing', 'progress' => 0, 'step' => 'Starting', 'message' => '', 'updated_at' => time()]),
        ];

        foreach ($files as $name => $content) {
            $agent->writeFile($server->vmid, $directory.'/'.$name, $content);
        }

        $task->completeStep('app_upload');
        $task->startStep('app_start');

        $attempt = $installation->attempts + 1;
        $result = $agent->run($server->vmid, [
            'systemd-run', '--unit=proxmotion-app-'.$installation->uuid.'-'.$attempt, '--collect', '--quiet',
            '/bin/bash', $directory.'/run.sh',
        ]);

        if (! $result->succeeded()) {
            $reason = trim(strtok($result->stderr."\n", "\n") ?: 'exit code '.$result->exitCode);

            throw new GuestScriptFailed('The installer could not be started: '.$reason);
        }

        $installation->forceFill([
            'status' => AppInstallationStatus::Installing,
            'attempts' => $attempt,
            'progress' => 0,
            'step' => 'Starting',
            'error' => null,
            'task_id' => $task->id,
            'started_at' => now(),
            'finished_at' => null,
        ])->save();

        $task->completeStep('app_start');
    }

    /**
     * What recipes may know about the server (server.json).
     *
     * @return array<string, string|null>
     */
    private function serverFacts(Server $server): array
    {
        $facts = ['hostname' => $server->hostname, 'primary_ipv4' => null, 'primary_ipv6' => null];

        foreach ([IpVersion::V4, IpVersion::V6] as $version) {
            $address = $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', $version))->value('address');
            $facts[$version === IpVersion::V4 ? 'primary_ipv4' : 'primary_ipv6'] = is_string($address) ? $address : null;
        }

        return $facts;
    }

    private static function json(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (strlen($json) > GuestAgentResource::MAX_WRITE_BYTES) {
            throw new GuestScriptFailed('The app\'s inputs are too large.');
        }

        return $json;
    }
}
