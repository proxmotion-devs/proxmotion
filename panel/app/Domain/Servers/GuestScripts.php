<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Models\GuestScript;
use App\Models\GuestScriptLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Proxmox\Data\GuestExecStatus;
use App\Proxmox\Exceptions\GuestAgentException;
use App\Proxmox\Exceptions\GuestCommandDisabled;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;
use Closure;
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
 * for critical events (password reset), which throw. Every run is logged
 * with its output (docs/adr/0023).
 */
final readonly class GuestScripts
{
    /** How long a freshly booted server may take until its guest agent answers. */
    public const AGENT_TIMEOUT_SECONDS = 600;

    /**
     * How long a new Windows server may take until its setup has finished
     * (sysprep's specialize pass, the restart into OOBE, Cloudbase-Init),
     * counted from the start of the wait. It stays well inside the task's
     * job timeout (RunServerTask::TIMEOUT), which also covers the clone.
     */
    public const WINDOWS_SETUP_TIMEOUT_SECONDS = 900;

    /**
     * Prints Windows' image state and the state of the Cloudbase-Init service
     * (empty when there is none), one per line. A fixed command: nothing in
     * it comes from the server or a customer.
     */
    public const WINDOWS_SETUP_COMMAND = [
        'powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
        "\$state = Get-ItemProperty -Path 'HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Setup\\State' -Name ImageState -ErrorAction SilentlyContinue; "
        .'$service = Get-Service -Name cloudbase-init -ErrorAction SilentlyContinue; '
        .'Write-Output ([string]$state.ImageState); Write-Output ([string]$service.Status)',
    ];

    /**
     * How long cloud-init of a new Linux system may still run once its guest
     * agent answers. The agent starts early in the boot, while cloud-init
     * still sets the hostname, /etc/hosts and the network.
     */
    public const CLOUD_INIT_TIMEOUT_SECONDS = 600;

    /**
     * Prints cloud-init's status ("status: running", "status: done", …), or
     * nothing on a system without cloud-init. A fixed command: nothing in it
     * comes from the server or a customer.
     */
    public const CLOUD_INIT_STATUS_COMMAND = ['sh', '-c', 'command -v cloud-init > /dev/null 2>&1 || exit 0; cloud-init status 2> /dev/null || true'];

    /** Runs of a script when the guest restarts while it runs. */
    private const ATTEMPTS = 3;

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
        return $this->resolve($template, $platform, $event)[0] ?? null;
    }

    /**
     * The script a run uses and where it comes from: with $draft the
     * template's saved draft (null without one), otherwise script().
     *
     * @return array{string, string}|null
     */
    public function resolve(?OsTemplate $template, OsPlatform $platform, GuestScriptEvent $event, bool $draft = false): ?array
    {
        $own = $template === null ? null : GuestScript::query()
            ->where('os_template_id', $template->id)
            ->where('event', $event->value)
            ->first();

        if ($draft) {
            return is_string($own?->draft_content) ? [$own->draft_content, GuestScriptLog::SOURCE_DRAFT] : null;
        }

        if (is_string($own?->content)) {
            return [$own->content, GuestScriptLog::SOURCE_PUBLISHED];
        }

        $default = self::defaultScript($platform, $event);

        return $default === null ? null : [$default, GuestScriptLog::SOURCE_DEFAULT];
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
     * their step to the caller. Every run is logged (GuestScriptLog). Only
     * trials pass $draft (the template's saved draft instead of its script)
     * and $trace (sh -x on Linux).
     *
     * @return bool whether the script ran and succeeded
     */
    public function run(Task $task, Server $server, GuestScriptEvent $event, bool $waitForAgent = false, ?string $input = null, bool $draft = false, bool $trace = false): bool
    {
        $platform = $this->platform($server);
        [$script, $source] = $this->resolve($server->osTemplate, $platform, $event, $draft) ?? [null, null];

        if ($script === null || $source === null) {
            return false;
        }

        $trace = $trace && $platform === OsPlatform::Linux;
        $task->startStep($event->step());
        $log = $this->startLog($task, $server, $event, $script, $source, $trace);
        $client = $this->clients->forCluster($server->cluster);
        $agent = $client->guestAgent($server->node->name);

        if (! ($waitForAgent ? $this->waitForAgent($server) : $agent->ping($server->vmid))) {
            return $this->failed($task, $log, $event, GuestAgentException::notReachable($server->vmid), self::unreachableNote($event));
        }

        $config = $client->qemu($server->node->name)->config($server->vmid) ?? [];
        $mac = self::macOf($config);

        if ($mac === null && $event === GuestScriptEvent::AddressesChanged) {
            return $this->failed($task, $log, $event, new GuestScriptFailed('The MAC address of the network interface is unknown.'), 'The MAC address of the network interface is unknown: configure the additional addresses inside the server.');
        }

        $inputs = ['server' => $server->uuid, 'hostname' => $server->hostname, 'mac' => $mac] + $this->inputs($server, $platform, $event, $config, $task);
        $log->forceFill(['inputs' => $inputs])->save();
        $name = $event->name().($source === GuestScriptLog::SOURCE_DRAFT ? '-draft' : '');

        for ($attempt = 1; ; $attempt++) {
            try {
                $result = $platform === OsPlatform::Windows
                    ? $this->runOnWindows($agent, $server->vmid, $event, $name, $script, $inputs, $input)
                    : $this->runOnLinux($agent, $server->vmid, $event, $name, $script, $inputs, $input, $trace);

                break;
            } catch (ProxmoxException $e) {
                // The agent went away mid-run: the guest restarted, as Windows does
                // during its first-boot setup (sysprep, cloudbase-init). Run the
                // script again once it is back; a critical script is never repeated.
                $lost = ! $event->isCritical() && $attempt < self::ATTEMPTS && ! $agent->ping($server->vmid);

                if (! $lost || ! $this->waitForAgent($server)) {
                    return $this->failed($task, $log, $event, $e, self::failedNote($event).$e->getMessage());
                }
            }
        }

        $this->finishLog($log, $event, $result);

        if (! $result->succeeded()) {
            $reason = self::reason($result, $trace);

            return $this->failed($task, $log, $event, new GuestScriptFailed(self::failedNote($event).$reason), self::failedNote($event).$reason);
        }

        if (! $event->isCritical()) {
            $task->completeStep($event->step());
        }

        return true;
    }

    /**
     * An administrator's trial run of one event's script on a server (a
     * guest_script task, input: event, draft, trace). Unlike during
     * operations, a failure fails the task.
     */
    public function runOnce(Task $task): void
    {
        $event = GuestScriptEvent::tryFrom(is_string($task->input['event'] ?? null) ? $task->input['event'] : '');

        if ($event === null || ! $event->canBeTried()) {
            throw new GuestScriptFailed('This script cannot be run on its own.');
        }

        $draft = ($task->input['draft'] ?? false) === true;

        if ($this->resolve($task->server->osTemplate, $this->platform($task->server), $event, $draft) === null) {
            throw new GuestScriptFailed($draft ? 'The server\'s OS template has no draft for this event.' : 'The server\'s OS template has no script for this event.');
        }

        if (! $this->run($task, $task->server, $event, draft: $draft, trace: ($task->input['trace'] ?? false) === true)) {
            $step = collect($task->steps)->firstWhere('key', $event->step());

            throw new GuestScriptFailed(is_string($step['message'] ?? null) ? $step['message'] : 'The script failed.');
        }
    }

    /**
     * After creating or reinstalling a server: its additional addresses, then
     * the template's own post-install script. The pipeline has waited for the
     * agent of the new system already (waitForAgentStep()).
     */
    public function afterInstall(Task $task, Server $server): void
    {
        $events = [];

        if ($this->addresses->additional($server) !== []) {
            $events[] = GuestScriptEvent::AddressesChanged;
        }

        if ($this->has($server, GuestScriptEvent::Provisioned)) {
            $events[] = GuestScriptEvent::Provisioned;
        }

        foreach ($events as $event) {
            $this->run($task, $server, $event);
        }
    }

    /**
     * The wait_agent step of a server that has just been started: it is ready
     * once its guest agent answers (password resets, scripts and apps need
     * it). When the agent does not answer in time the server still runs, so
     * the step records that and the operation goes on (docs/adr/0004).
     *
     * With $setup (a new or reinstalled system), a Windows server is ready
     * only once Windows setup has finished: its agent already answers during
     * sysprep's specialize pass, before Windows restarts into OOBE and before
     * Cloudbase-Init runs. If setup does not finish in time, the step records
     * that and the operation goes on as well. A Linux server is ready once
     * cloud-init has finished (checkCloudInit()), so that it does not undo
     * what the guest scripts set (the hostname, /etc/hosts).
     */
    public function waitForAgentStep(Task $task, Server $server, int $timeoutSeconds = self::AGENT_TIMEOUT_SECONDS, bool $setup = false): bool
    {
        $state = [];

        while (($delay = $this->checkReady($task, $server, $state, $setup, $timeoutSeconds)) !== null) {
            Sleep::for($delay)->seconds();
        }

        return ($state['answered'] ?? false) === true;
    }

    /**
     * One check of the wait that waitForAgentStep() describes, for staged
     * tasks that wait between runs instead of in place (docs/adr/0032).
     * $state carries what the wait remembers from one check to the next
     * (plain values only, kept on the task between runs); 'answered' says
     * whether the agent answered. Without a $step the wait records nothing.
     *
     * @param  array<string, mixed>  $state
     * @return int|null null once the wait is over (the step is complete), else the seconds until the next check
     */
    public function checkReady(Task $task, Server $server, array &$state, bool $setup = false, int $timeoutSeconds = self::AGENT_TIMEOUT_SECONDS, ?string $step = 'wait_agent'): ?int
    {
        $now = Carbon::now()->getTimestamp();

        if (! is_int($state['since'] ?? null)) {
            $state['since'] = $now;

            if ($step !== null) {
                $task->startStep($step);
            }
        }

        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $done = function (?string $note) use ($task, $step): null {
            if ($step !== null) {
                $task->completeStep($step, $note);
            }

            return null;
        };

        if ($setup && $this->platform($server) === OsPlatform::Windows) {
            return $this->checkWindowsSetup($agent, $server, $state, $timeoutSeconds, $done);
        }

        if (($state['answered'] ?? false) !== true) {
            if (! $agent->ping($server->vmid)) {
                return $now >= $state['since'] + $timeoutSeconds ? $done(self::agentTimeoutNote($timeoutSeconds)) : 5;
            }

            $state['answered'] = true;

            if (! $setup) {
                return $done(null);
            }

            $state['cloud_init_since'] = $now;
        }

        return $this->checkCloudInit($agent, $server, $state, $done);
    }

    /**
     * One check whether cloud-init still runs (done, error, disabled, or not
     * installed end the wait). The agent going away meanwhile (cloud-init
     * restarting the guest) counts as a check that has not passed yet.
     *
     * @param  array<string, mixed>  $state
     * @param  Closure(?string): null  $done
     */
    private function checkCloudInit(GuestAgentResource $agent, Server $server, array $state, Closure $done): ?int
    {
        try {
            $result = $agent->run($server->vmid, self::CLOUD_INIT_STATUS_COMMAND, timeoutSeconds: 60);
            $status = preg_match('/^status:\s*(.+)$/m', $result->stdout, $match) === 1 ? trim($match[1]) : '';

            if (! in_array($status, ['running', 'not started'], true)) {
                return $done(in_array($status, ['error', 'degraded', 'degraded done'], true)
                    ? "cloud-init finished with status {$status}: check /var/log/cloud-init.log inside the server."
                    : null);
            }
        } catch (GuestCommandDisabled $e) {
            return $done('Could not check whether cloud-init has finished: '.$e->getMessage());
        } catch (ProxmoxException) {
            // The guest restarted while the check ran: check again once it is back.
        }

        $since = is_int($state['cloud_init_since'] ?? null) ? $state['cloud_init_since'] : $state['since'];

        if (Carbon::now()->getTimestamp() >= $since + self::CLOUD_INIT_TIMEOUT_SECONDS) {
            return $done('cloud-init was still running after '.intdiv(self::CLOUD_INIT_TIMEOUT_SECONDS, 60).' minutes: the server may still be setting itself up.');
        }

        return 5;
    }

    /**
     * One check whether Windows reports IMAGE_STATE_COMPLETE and
     * Cloudbase-Init no longer runs. The agent goes away while Windows
     * restarts during setup: that counts as a check that has not passed yet.
     * The agent must still answer for the first time within $agentTimeoutSeconds
     * (a template without one).
     *
     * @param  array<string, mixed>  $state
     * @param  Closure(?string): null  $done
     */
    private function checkWindowsSetup(GuestAgentResource $agent, Server $server, array &$state, int $agentTimeoutSeconds, Closure $done): ?int
    {
        $timeoutSeconds = max($agentTimeoutSeconds, self::WINDOWS_SETUP_TIMEOUT_SECONDS);

        if ($agent->ping($server->vmid)) {
            $state['answered'] = true;

            if ($this->windowsSetupFinished($agent, $server->vmid)) {
                return $done(null);
            }
        }

        $now = Carbon::now()->getTimestamp();

        if (($state['answered'] ?? false) !== true && $now >= $state['since'] + $agentTimeoutSeconds) {
            return $done(self::agentTimeoutNote($agentTimeoutSeconds));
        }

        if ($now >= $state['since'] + $timeoutSeconds) {
            return $done('Windows setup did not finish within '.intdiv($timeoutSeconds, 60).' minutes: the server may still be setting itself up.');
        }

        return 5;
    }

    private function windowsSetupFinished(GuestAgentResource $agent, int $vmid): bool
    {
        try {
            $result = $agent->run($vmid, self::WINDOWS_SETUP_COMMAND, timeoutSeconds: 60);
        } catch (ProxmoxException) {
            // Windows restarted while the check ran.
            return false;
        }

        if (! $result->succeeded()) {
            return false;
        }

        $lines = array_map(trim(...), explode("\n", $result->stdout));

        // No image state: a template that was never generalized, so no setup to wait for.
        return in_array($lines[0], ['', 'IMAGE_STATE_COMPLETE'], true)
            && in_array($lines[1] ?? '', ['', 'Stopped'], true);
    }

    private static function agentTimeoutNote(int $timeoutSeconds): string
    {
        return 'The guest agent did not answer within '.intdiv($timeoutSeconds, 60).' minutes.';
    }

    /**
     * Wait for the agent of a freshly booted server.
     */
    public function waitForAgent(Server $server, int $timeoutSeconds = self::AGENT_TIMEOUT_SECONDS): bool
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $deadline = Carbon::now()->addSeconds($timeoutSeconds);

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
    public function inputs(Server $server, OsPlatform $platform, GuestScriptEvent $event, array $config = [], ?Task $task = null): array
    {
        return match ($event) {
            GuestScriptEvent::Provisioned => ['addresses' => $this->addresses->all($server)],
            // The primary may differ from the one cloud-init configured (docs/adr/0011).
            GuestScriptEvent::AddressesChanged => ['addresses' => $this->addresses->additional($server)]
                + $this->addresses->primaries($server)
                + GuestAddresses::cloudInit($config),
            GuestScriptEvent::DiskResized => ['disk_mib' => $server->disk_mib],
            GuestScriptEvent::PasswordReset => ['username' => $platform->administrator()],
            GuestScriptEvent::HostnameChanged => ['previous_hostname' => is_string($task?->input['previous_hostname'] ?? null) ? $task->input['previous_hostname'] : null],
        };
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $inputs
     */
    private function runOnLinux(GuestAgentResource $agent, int $vmid, GuestScriptEvent $event, string $name, string $script, array $inputs, ?string $input, bool $trace): GuestExecStatus
    {
        // A draft has its own path ($name ends in -draft), so boot units that
        // run the published script never pick it up; the inputs are the same.
        $path = '/usr/local/sbin/proxmotion-'.$name;
        $config = self::LINUX_CONFIG_DIR.'/'.$event->name();

        $agent->runOrFail($vmid, ['mkdir', '-p', self::LINUX_CONFIG_DIR, '/usr/local/sbin']);
        $agent->writeFile($vmid, $config.'.json', self::json($inputs));
        $agent->writeFile($vmid, $config.'.vars', self::vars($inputs));
        $agent->writeFile($vmid, $path, $script);

        return $agent->run($vmid, $trace ? ['sh', '-x', $path] : ['sh', $path], $input, timeoutSeconds: $event->isCritical() ? 60 : 300);
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $inputs
     */
    private function runOnWindows(GuestAgentResource $agent, int $vmid, GuestScriptEvent $event, string $name, string $script, array $inputs, ?string $input): GuestExecStatus
    {
        $path = self::WINDOWS_DIR.'\\'.$name.'.ps1';

        // cmd starts much faster than PowerShell; "already exists" fails harmlessly.
        $agent->run($vmid, ['cmd.exe', '/c', 'mkdir', self::WINDOWS_DIR]);
        $agent->writeFile($vmid, self::WINDOWS_DIR.'\\'.$event->name().'.json', self::json($inputs));
        $agent->writeFile($vmid, $path, $script);

        return $agent->run($vmid, ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $path], $input, timeoutSeconds: $event->isCritical() ? 60 : 300);
    }

    private function startLog(Task $task, Server $server, GuestScriptEvent $event, string $script, string $source, bool $trace): GuestScriptLog
    {
        $log = new GuestScriptLog;
        $log->forceFill([
            'server_id' => $server->id,
            'os_template_id' => $server->os_template_id,
            'task_id' => $task->id,
            'event' => $event,
            'source' => $source,
            'trace' => $trace,
            'status' => 'running',
            'script' => $script,
            'inputs' => [],
        ])->save();

        return $log;
    }

    /**
     * Record the exit code and output; a password script's output is left out.
     */
    private function finishLog(GuestScriptLog $log, GuestScriptEvent $event, GuestExecStatus $result): void
    {
        $keep = $event !== GuestScriptEvent::PasswordReset;
        $truncated = $result->stdoutTruncated || $result->stderrTruncated
            || strlen($result->stdout) > GuestScriptLog::MAX_OUTPUT_BYTES
            || strlen($result->stderr) > GuestScriptLog::MAX_OUTPUT_BYTES;

        $log->forceFill([
            'status' => $result->succeeded() ? 'succeeded' : 'failed',
            'exit_code' => $result->exitCode,
            'message' => $result->signal !== null ? 'Ended by signal '.$result->signal.'.' : null,
            'stdout' => $keep ? self::tail($result->stdout) : null,
            'stderr' => $keep ? self::tail($result->stderr) : null,
            'output_truncated' => $keep && $truncated,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * The end of an output, where the error usually is.
     */
    private static function tail(string $output): string
    {
        return strlen($output) > GuestScriptLog::MAX_OUTPUT_BYTES
            ? mb_strcut($output, strlen($output) - GuestScriptLog::MAX_OUTPUT_BYTES, null, 'UTF-8')
            : $output;
    }

    /**
     * The first line of stderr (without sh -x trace lines), or the exit code.
     */
    private static function reason(GuestExecStatus $result, bool $trace): string
    {
        $lines = array_filter(
            array_map(trim(...), explode("\n", $result->stderr)),
            fn (string $line) => $line !== '' && ! ($trace && str_starts_with($line, '+')),
        );

        return reset($lines) ?: 'exit code '.$result->exitCode;
    }

    private function failed(Task $task, GuestScriptLog $log, GuestScriptEvent $event, Throwable $error, string $note): bool
    {
        if ($log->status === 'running') {
            $log->forceFill(['status' => 'failed', 'message' => $note, 'finished_at' => now()])->save();
        }

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
            GuestScriptEvent::HostnameChanged => 'The guest agent did not answer: change the hostname inside the server.',
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
            GuestScriptEvent::HostnameChanged => 'The hostname could not be changed inside the server: ',
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
