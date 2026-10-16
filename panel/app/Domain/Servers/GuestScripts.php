<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Models\GuestScript;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Proxmox\Data\GuestExecStatus;
use App\Proxmox\Exceptions\GuestAgentException;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Runs the script of an event inside a server through the QEMU guest agent
 * (docs/adr/0010): the OS template's own script, or the default from
 * resources/guest for its platform. The script and its inputs (from the
 * panel's database only, never interpolated into the script) are written with
 * file-write, then the script runs with exec.
 *
 * Problems are recorded on the task step and never fail the operation, except
 * for critical events (password reset), which throw.
 */
final readonly class GuestScripts
{
    /** How long a freshly booted server may take until its guest agent answers. */
    public const AGENT_TIMEOUT_SECONDS = 600;

    /** The largest script one file-write accepts. */
    public const MAX_SCRIPT_BYTES = GuestAgentResource::MAX_WRITE_BYTES;

    private const LINUX_CONFIG_DIR = '/etc/proxmotion';

    private const WINDOWS_DIR = 'C:\\ProgramData\\Proxmotion';

    public function __construct(
        private ProxmoxClientFactory $clients,
        private GuestAddresses $addresses,
    ) {}

    public function platform(Server $server): OsPlatform
    {
        return $server->osTemplate->platform ?? OsPlatform::Linux;
    }

    /**
     * The script that runs for the event: the template's own or the default.
     */
    public function script(?OsTemplate $template, OsPlatform $platform, GuestScriptEvent $event): ?string
    {
        $own = $template === null ? null : GuestScript::query()
            ->where('os_template_id', $template->id)
            ->where('event', $event->value)
            ->value('content');

        return is_string($own) ? $own : self::defaultScript($platform, $event);
    }

    public static function defaultScript(OsPlatform $platform, GuestScriptEvent $event): ?string
    {
        if (! $event->hasDefault()) {
            return null;
        }

        $file = resource_path('guest/'.$platform->value.'/'.$event->name().($platform === OsPlatform::Windows ? '.ps1' : '.sh'));

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function has(Server $server, GuestScriptEvent $event): bool
    {
        return $this->script($server->osTemplate, $this->platform($server), $event) !== null;
    }

    /**
     * Run the event's script, if there is one. With $waitForAgent, wait for
     * the agent of a freshly booted server first; otherwise one ping decides.
     * Critical events throw instead of leaving a note, and leave completing
     * their step to the caller.
     *
     * @return bool whether the script ran and succeeded
     */
    public function run(Task $task, Server $server, GuestScriptEvent $event, bool $waitForAgent = false, ?string $input = null): bool
    {
        $platform = $this->platform($server);
        $script = $this->script($server->osTemplate, $platform, $event);

        if ($script === null) {
            return false;
        }

        $task->startStep($event->step());
        $client = $this->clients->forCluster($server->cluster);
        $agent = $client->guestAgent($server->node->name);

        if (! ($waitForAgent ? $this->waitForAgent($server) : $agent->ping($server->vmid))) {
            return $this->failed($task, $event, GuestAgentException::notReachable($server->vmid), self::unreachableNote($event));
        }

        $config = $client->qemu($server->node->name)->config($server->vmid) ?? [];
        $mac = self::macOf($config);

        if ($mac === null && $event === GuestScriptEvent::AddressesChanged) {
            return $this->failed($task, $event, new GuestScriptFailed('The MAC address of the network interface is unknown.'), 'The MAC address of the network interface is unknown: configure the additional addresses inside the server.');
        }

        $inputs = ['server' => $server->uuid, 'hostname' => $server->hostname, 'mac' => $mac] + $this->inputs($server, $platform, $event, $config);

        try {
            $result = $platform === OsPlatform::Windows
                ? $this->runOnWindows($agent, $server->vmid, $event, $script, $inputs, $input)
                : $this->runOnLinux($agent, $server->vmid, $event, $script, $inputs, $input);
        } catch (ProxmoxException $e) {
            return $this->failed($task, $event, $e, self::failedNote($event).$e->getMessage());
        }

        if (! $result->succeeded()) {
            $reason = trim(strtok($result->stderr."\n", "\n") ?: 'exit code '.$result->exitCode);

            return $this->failed($task, $event, new GuestScriptFailed(self::failedNote($event).$reason), self::failedNote($event).$reason);
        }

        if (! $event->isCritical()) {
            $task->completeStep($event->step());
        }

        return true;
    }

    /**
     * An administrator's trial run of one event's script on a server (a
     * guest_script task). Unlike during operations, a failure fails the task.
     */
    public function runOnce(Task $task): void
    {
        $event = GuestScriptEvent::tryFrom(is_string($task->input['event'] ?? null) ? $task->input['event'] : '');

        if ($event === null || ! $event->canBeTried()) {
            throw new GuestScriptFailed('This script cannot be run on its own.');
        }

        if (! $this->has($task->server, $event)) {
            throw new GuestScriptFailed('The server\'s OS template has no script for this event.');
        }

        if (! $this->run($task, $task->server, $event)) {
            $step = collect($task->steps)->firstWhere('key', $event->step());

            throw new GuestScriptFailed(is_string($step['message'] ?? null) ? $step['message'] : 'The script failed.');
        }
    }

    /**
     * After creating or reinstalling a server: its additional addresses, then
     * the template's own post-install script. Waits for the agent of the new
     * system only when there is something to run.
     */
    public function afterInstall(Task $task, Server $server, bool $waitForAgent): void
    {
        $events = [];

        if ($this->addresses->additional($server) !== []) {
            $events[] = GuestScriptEvent::AddressesChanged;
        }

        if ($this->has($server, GuestScriptEvent::Provisioned)) {
            $events[] = GuestScriptEvent::Provisioned;
        }

        if ($events !== [] && $waitForAgent) {
            // Waiting for the first boot can take minutes: show it as the first script's step.
            $task->startStep($events[0]->step());
            $this->waitForAgent($server);
        }

        foreach ($events as $event) {
            $this->run($task, $server, $event);
        }
    }

    /**
     * Wait for the agent of a freshly booted server.
     */
    public function waitForAgent(Server $server): bool
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $deadline = Carbon::now()->addSeconds(self::AGENT_TIMEOUT_SECONDS);

        while (! $agent->ping($server->vmid)) {
            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                return false;
            }

            Sleep::for(5)->seconds();
        }

        return true;
    }

    /**
     * What a script gets besides server, hostname and mac. Lists are lists of
     * strings; nothing here comes from customer input other than validated
     * values already stored on the server.
     *
     * @param  array<string, mixed>  $config  the VM's configuration
     * @return array<string, string|int|list<string>|null>
     */
    public function inputs(Server $server, OsPlatform $platform, GuestScriptEvent $event, array $config = []): array
    {
        return match ($event) {
            GuestScriptEvent::Provisioned => ['addresses' => $this->addresses->all($server)],
            // The primary may differ from the one cloud-init configured (docs/adr/0011).
            GuestScriptEvent::AddressesChanged => ['addresses' => $this->addresses->additional($server)]
                + $this->addresses->primaries($server)
                + GuestAddresses::cloudInit($config),
            GuestScriptEvent::DiskResized => ['disk_mib' => $server->disk_mib],
            GuestScriptEvent::PasswordReset => ['username' => $platform->administrator()],
        };
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $inputs
     */
    private function runOnLinux(GuestAgentResource $agent, int $vmid, GuestScriptEvent $event, string $script, array $inputs, ?string $input): GuestExecStatus
    {
        $path = '/usr/local/sbin/proxmotion-'.$event->name();
        $config = self::LINUX_CONFIG_DIR.'/'.$event->name();

        $agent->run($vmid, ['mkdir', '-p', self::LINUX_CONFIG_DIR, '/usr/local/sbin']);
        $agent->writeFile($vmid, $config.'.json', self::json($inputs));
        $agent->writeFile($vmid, $config.'.vars', self::vars($inputs));
        $agent->writeFile($vmid, $path, $script);

        return $agent->run($vmid, ['sh', $path], $input, timeoutSeconds: $event->isCritical() ? 60 : 300);
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $inputs
     */
    private function runOnWindows(GuestAgentResource $agent, int $vmid, GuestScriptEvent $event, string $script, array $inputs, ?string $input): GuestExecStatus
    {
        $path = self::WINDOWS_DIR.'\\'.$event->name().'.ps1';

        // cmd starts much faster than PowerShell; "already exists" fails harmlessly.
        $agent->run($vmid, ['cmd.exe', '/c', 'mkdir', self::WINDOWS_DIR]);
        $agent->writeFile($vmid, self::WINDOWS_DIR.'\\'.$event->name().'.json', self::json($inputs));
        $agent->writeFile($vmid, $path, $script);

        return $agent->run($vmid, ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $path], $input, timeoutSeconds: $event->isCritical() ? 60 : 300);
    }

    private function failed(Task $task, GuestScriptEvent $event, Throwable $error, string $note): bool
    {
        if ($event->isCritical()) {
            throw $error instanceof GuestScriptFailed || $error instanceof ProxmoxException ? $error : new GuestScriptFailed($note);
        }

        $task->completeStep($event->step(), $note);

        return false;
    }

    private static function unreachableNote(GuestScriptEvent $event): string
    {
        return match ($event) {
            GuestScriptEvent::AddressesChanged => 'The guest agent did not answer: configure the additional addresses inside the server.',
            GuestScriptEvent::DiskResized => 'The guest agent did not answer: grow the file system inside the server (Linux templates with cloud-init grow it at the next boot).',
            default => 'The guest agent did not answer: the template\'s script did not run.',
        };
    }

    private static function failedNote(GuestScriptEvent $event): string
    {
        return match ($event) {
            GuestScriptEvent::AddressesChanged => 'The additional addresses could not be configured inside the server: ',
            GuestScriptEvent::DiskResized => 'The file system could not be grown inside the server: ',
            GuestScriptEvent::PasswordReset => 'The password reset script failed: ',
            GuestScriptEvent::Provisioned => 'The template\'s script failed: ',
        };
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $inputs
     */
    private static function json(array $inputs): string
    {
        return json_encode($inputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * key=value lines for shell scripts; a list repeats its key, an empty one
     * leaves it out. Values never contain line breaks.
     *
     * @param  array<string, string|int|list<string>|null>  $inputs
     */
    private static function vars(array $inputs): string
    {
        $lines = [];

        foreach ($inputs as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item !== null) {
                    $lines[] = $key.'='.str_replace(["\r", "\n"], '', (string) $item);
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function macOf(array $config): ?string
    {
        $net0 = is_string($config['net0'] ?? null) ? $config['net0'] : '';

        return preg_match('/=([0-9A-F]{2}(?::[0-9A-F]{2}){5})/i', $net0, $match) === 1 ? strtolower($match[1]) : null;
    }
}
