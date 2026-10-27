<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Enums\TaskType;
use App\Models\CustomScript;
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
     * Prints key=value lines: image= (Windows' image state), name= and
     * active_name= (the computer name after the next restart and the one in
     * use), and with a cloudbase-init service service= (its state), plugins=
     * (the plugins its configuration file lists), done= and later= (the
     * plugins it recorded as done, or to run at the next boot, for the
     * instance ID on the config drive). A fixed command: nothing in it comes
     * from the server or a customer.
     *
     * Cloudbase-Init keeps a plugin's state under
     * HKLM\SOFTWARE\Cloudbase Solutions\Cloudbase-Init\<instance ID>\Plugins
     * (1 done, 2 at the next boot); the instance ID is the uuid of
     * openstack\latest\meta_data.json on the config drive (label config-2).
     */
    public const WINDOWS_SETUP_SCRIPT = <<<'PS'
        $ErrorActionPreference = 'SilentlyContinue'
        Write-Output ('image=' + (Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Setup\State').ImageState)
        $names = 'HKLM:\SYSTEM\CurrentControlSet\Control\ComputerName'
        Write-Output ('name=' + (Get-ItemProperty -Path ($names + '\ComputerName')).ComputerName)
        Write-Output ('active_name=' + (Get-ItemProperty -Path ($names + '\ActiveComputerName')).ComputerName)
        $service = Get-Service -Name cloudbase-init
        if ($service) {
            Write-Output ('service=' + $service.Status)
            $conf = Join-Path $env:ProgramFiles 'Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init.conf'
            if ((Get-CimInstance -ClassName Win32_Service -Filter 'Name=''cloudbase-init''').PathName -match '--config-file\s+\W?([a-z]:\\.+?\.conf)') { $conf = $Matches[1] }
            $line = Get-Content -Path $conf | Where-Object { $_ -match '^\s*plugins\s*=' } | Select-Object -First 1
            if ($line) { Write-Output ('plugins=' + ((($line -split '=', 2)[1] -split ',' | ForEach-Object { ($_.Trim() -split '\.')[-1] } | Where-Object { $_ }) -join ',')) }
            $drive = Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType=5' | Where-Object { $_.VolumeName -eq 'config-2' } | Select-Object -First 1
            $instance = if ($drive) { (Get-Content -Raw -Path ($drive.DeviceID + '\openstack\latest\meta_data.json') | ConvertFrom-Json).uuid } else { '' }
            foreach ($root in 'HKLM:\SOFTWARE\Cloudbase Solutions\Cloudbase-Init', 'HKLM:\SOFTWARE\WOW6432Node\Cloudbase Solutions\Cloudbase-Init') {
                $key = if ($instance) { Get-Item -Path (Join-Path $root ($instance + '\Plugins')) } else { $null }
                if ($key) {
                    Write-Output ('done=' + (($key.Property | Where-Object { $key.GetValue($_) -eq 1 }) -join ','))
                    Write-Output ('later=' + (($key.Property | Where-Object { $key.GetValue($_) -ne 1 }) -join ','))
                    break
                }
            }
        }
        exit 0
        PS;

    public const WINDOWS_SETUP_COMMAND = ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', self::WINDOWS_SETUP_SCRIPT];

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

    /**
     * Statuses of a cloud-init that has not finished yet. Before 24.1 one that
     * has not started says "not run" (24.1: "not started") and one running
     * after a recoverable error "degraded running" (24.1: "running"); EL8 and
     * Debian 12 ship such versions.
     */
    private const CLOUD_INIT_WAITING = ['not started', 'not run', 'running', 'degraded running'];

    /** Statuses of a cloud-init that has finished with errors (before 24.1 also "degraded done"). */
    private const CLOUD_INIT_FAILED = ['error', 'degraded', 'degraded done'];

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
     * Run the event's script, if there is one, then the custom scripts bound
     * to the event (docs/adr/0043; not in trials, and after a password reset
     * the pipeline runs them once the password is stored). With
     * $waitForAgent, wait for the agent of a freshly booted server first;
     * otherwise one ping decides. Critical events throw instead of leaving a
     * note, and leave completing their step to the caller. Every run is
     * logged (GuestScriptLog). Only trials pass $draft (the template's saved
     * draft instead of its script) and $trace (sh -x on Linux). Without
     * $custom only the event's own script runs.
     *
     * @return bool whether the event's own script ran and succeeded
     */
    public function run(Task $task, Server $server, GuestScriptEvent $event, bool $waitForAgent = false, ?string $input = null, bool $draft = false, bool $trace = false, bool $custom = true): bool
    {
        $resolved = $this->resolve($server->osTemplate, $this->platform($server), $event, $draft);
        $succeeded = $resolved !== null
            && $this->execute($task, $server, ScriptSlot::event($event), $resolved[0], $resolved[1], $waitForAgent, $input, $trace);

        if ($custom && $task->type !== TaskType::GuestScript && ! $event->isCritical()) {
            $this->runCustom($task, $server, $event, waitForAgent: $waitForAgent && $resolved === null);
        }

        return $succeeded;
    }

    /**
     * The published custom scripts bound to the event, in their order. Each
     * leaves a note when it fails; when the agent does not answer, each run
     * notes that after one ping instead of waiting again.
     */
    public function runCustom(Task $task, Server $server, GuestScriptEvent $event, bool $waitForAgent = false): void
    {
        $scripts = $this->customScripts($server, $event);

        if ($scripts !== [] && $waitForAgent) {
            $this->waitForAgent($server);
        }

        foreach ($scripts as $script) {
            $this->execute($task, $server, ScriptSlot::custom($script, $event, $event->value), (string) $script->content, GuestScriptLog::SOURCE_PUBLISHED);
        }
    }

    /**
     * The published custom scripts of the server's template bound to the event.
     *
     * @return list<CustomScript>
     */
    public function customScripts(Server $server, GuestScriptEvent $event): array
    {
        if ($server->os_template_id === null) {
            return [];
        }

        return array_values(CustomScript::query()
            ->where('os_template_id', $server->os_template_id)
            ->whereNotNull('content')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (CustomScript $script) => $script->runsAt($event))
            ->all());
    }

    /**
     * The task steps of the custom scripts bound to the event (TaskPlan).
     *
     * @return list<string>
     */
    public function customSteps(Server $server, GuestScriptEvent $event): array
    {
        return array_map(fn (CustomScript $script) => $script->step($event), $this->customScripts($server, $event));
    }

    /**
     * Write one script and its inputs into the guest, run it and log the run.
     *
     * @return bool whether it ran and succeeded
     */
    private function execute(Task $task, Server $server, ScriptSlot $slot, string $script, string $source, bool $waitForAgent = false, ?string $input = null, bool $trace = false): bool
    {
        $platform = $this->platform($server);
        $trace = $trace && $platform === OsPlatform::Linux;
        $task->startStep($slot->step);
        $log = $this->startLog($task, $server, $slot, $script, $source, $trace);
        $client = $this->clients->forCluster($server->cluster);
        $agent = $client->guestAgent($server->node->name);

        if (! ($waitForAgent ? $this->waitForAgent($server) : $agent->ping($server->vmid))) {
            return $this->failed($task, $log, $slot, GuestAgentException::notReachable($server->vmid), $slot->unreachableNote());
        }

        $config = $client->qemu($server->node->name)->config($server->vmid) ?? [];
        $mac = self::macOf($config);

        if ($mac === null && $slot->custom === null && $slot->event === GuestScriptEvent::AddressesChanged) {
            return $this->failed($task, $log, $slot, new GuestScriptFailed('The MAC address of the network interface is unknown.'), 'The MAC address of the network interface is unknown: configure the additional addresses inside the server.');
        }

        $inputs = ['server' => $server->uuid, 'hostname' => $server->hostname, 'mac' => $mac] + $this->slotInputs($server, $platform, $slot, $config, $task);
        $log->forceFill(['inputs' => $inputs])->save();
        $name = $slot->file.($source === GuestScriptLog::SOURCE_DRAFT ? '-draft' : '');

        for ($attempt = 1; ; $attempt++) {
            try {
                $result = $platform === OsPlatform::Windows
                    ? $this->runOnWindows($agent, $server->vmid, $slot, $name, $script, $inputs, $input)
                    : $this->runOnLinux($agent, $server->vmid, $slot, $name, $script, $inputs, $input, $trace);

                break;
            } catch (ProxmoxException $e) {
                // The agent went away mid-run: the guest restarted, as Windows does
                // during its first-boot setup (sysprep, cloudbase-init). Run the
                // script again once it is back; a critical script is never repeated.
                $lost = ! $slot->isCritical() && $attempt < self::ATTEMPTS && ! $agent->ping($server->vmid);

                if (! $lost || ! $this->waitForAgent($server)) {
                    return $this->failed($task, $log, $slot, $e, $slot->failedNote().$e->getMessage());
                }
            }
        }

        $this->finishLog($log, $slot, $result);

        if (! $result->succeeded()) {
            $reason = self::reason($result, $trace);

            return $this->failed($task, $log, $slot, new GuestScriptFailed($slot->failedNote().$reason), $slot->failedNote().$reason);
        }

        if (! $slot->isCritical()) {
            $task->completeStep($slot->step);
        }

        return true;
    }

    /**
     * An administrator's run of one script on a server (a guest_script task):
     * a trial of an event's script (input: event, draft, trace), or a custom
     * script (input: script, draft, trace, manual for a run from the server
     * page). Unlike during operations, a failure fails the task.
     */
    public function runOnce(Task $task): void
    {
        $server = $task->server;
        $draft = ($task->input['draft'] ?? false) === true;
        $trace = ($task->input['trace'] ?? false) === true;

        if (is_string($task->input['script'] ?? null)) {
            $custom = CustomScript::query()
                ->where('uuid', $task->input['script'])
                ->where('os_template_id', $server->os_template_id)
                ->first() ?? throw new GuestScriptFailed('The script no longer exists.');
            $content = $draft ? $custom->draft_content : $custom->content;

            if ($content === null) {
                throw new GuestScriptFailed($draft ? 'The script has no draft.' : 'The script is not published.');
            }

            $slot = ScriptSlot::custom($custom, null, ($task->input['manual'] ?? false) === true ? 'manual' : 'trial');
            $this->runOrThrow($task, $slot, fn () => $this->execute($task, $server, $slot, $content, $draft ? GuestScriptLog::SOURCE_DRAFT : GuestScriptLog::SOURCE_PUBLISHED, trace: $trace));

            return;
        }

        $event = GuestScriptEvent::tryFrom(is_string($task->input['event'] ?? null) ? $task->input['event'] : '');

        if ($event === null || ! $event->canBeTried()) {
            throw new GuestScriptFailed('This script cannot be run on its own.');
        }

        if ($this->resolve($server->osTemplate, $this->platform($server), $event, $draft) === null) {
            throw new GuestScriptFailed($draft ? 'The server\'s OS template has no draft for this event.' : 'The server\'s OS template has no script for this event.');
        }

        $this->runOrThrow($task, ScriptSlot::event($event), fn () => $this->run($task, $server, $event, draft: $draft, trace: $trace));
    }

    /**
     * @param  Closure(): bool  $run
     */
    private function runOrThrow(Task $task, ScriptSlot $slot, Closure $run): void
    {
        if (! $run()) {
            $step = collect($task->steps)->firstWhere('key', $slot->step);

            throw new GuestScriptFailed(is_string($step['message'] ?? null) ? $step['message'] : 'The script failed.');
        }
    }

    /**
     * After creating or reinstalling a server: its additional addresses, then
     * the template's own post-install script, each followed by the custom
     * scripts bound to it. The pipeline has waited for the agent of the new
     * system already (waitForAgentStep()).
     */
    public function afterInstall(Task $task, Server $server): void
    {
        if ($this->addresses->additional($server) !== []) {
            $this->run($task, $server, GuestScriptEvent::AddressesChanged);
        }

        $this->run($task, $server, GuestScriptEvent::Provisioned);
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
     * One check whether cloud-init still runs or has yet to start
     * (CLOUD_INIT_WAITING; done, an error, disabled, or not installed end the
     * wait). The agent going away meanwhile (cloud-init restarting the guest)
     * counts as a check that has not passed yet. The last status seen is kept
     * in $state['cloud_init_status'] for the note of a wait that times out.
     *
     * @param  array<string, mixed>  $state
     * @param  Closure(?string): null  $done
     */
    private function checkCloudInit(GuestAgentResource $agent, Server $server, array &$state, Closure $done): ?int
    {
        try {
            $result = $agent->run($server->vmid, self::CLOUD_INIT_STATUS_COMMAND, timeoutSeconds: 60);
            $status = preg_match('/^status:\s*(.+)$/m', $result->stdout, $match) === 1 ? trim($match[1]) : '';

            if (! in_array($status, self::CLOUD_INIT_WAITING, true)) {
                return $done(in_array($status, self::CLOUD_INIT_FAILED, true)
                    ? "cloud-init finished with status {$status}: check /var/log/cloud-init.log inside the server."
                    : null);
            }

            $state['cloud_init_status'] = $status;
        } catch (GuestCommandDisabled $e) {
            return $done('Could not check whether cloud-init has finished: '.$e->getMessage());
        } catch (ProxmoxException) {
            // The guest restarted while the check ran: check again once it is back.
        }

        $since = is_int($state['cloud_init_since'] ?? null) ? $state['cloud_init_since'] : $state['since'];

        if (Carbon::now()->getTimestamp() >= $since + self::CLOUD_INIT_TIMEOUT_SECONDS) {
            $minutes = intdiv(self::CLOUD_INIT_TIMEOUT_SECONDS, 60);

            return $done(in_array($state['cloud_init_status'] ?? null, ['not started', 'not run'], true)
                ? "cloud-init had not started after {$minutes} minutes: check that the template's cloud-init is enabled and finds the CloudInit drive."
                : "cloud-init was still running after {$minutes} minutes: the server may still be setting itself up.");
        }

        return 5;
    }

    /**
     * One check whether Windows setup and Cloudbase-Init have finished
     * (windowsSetupPending()). The agent goes away while Windows restarts
     * during setup: that counts as a check that has not passed yet. The agent
     * must still answer for the first time within $agentTimeoutSeconds (a
     * template without one). What the last check still waited for is kept in
     * $state['waiting_for'] for the note of a wait that times out.
     *
     * @param  array<string, mixed>  $state
     * @param  Closure(?string): null  $done
     */
    private function checkWindowsSetup(GuestAgentResource $agent, Server $server, array &$state, int $agentTimeoutSeconds, Closure $done): ?int
    {
        $timeoutSeconds = max($agentTimeoutSeconds, self::WINDOWS_SETUP_TIMEOUT_SECONDS);

        if ($agent->ping($server->vmid)) {
            $state['answered'] = true;

            try {
                $result = $agent->run($server->vmid, self::WINDOWS_SETUP_COMMAND, timeoutSeconds: 60);
                $pending = $result->succeeded() ? self::windowsSetupPending($result->stdout) : 'the setup check did not succeed';
            } catch (ProxmoxException) {
                // Windows restarted while the check ran.
                $pending = is_string($state['waiting_for'] ?? null) ? $state['waiting_for'] : 'Windows restarted during the check';
            }

            if ($pending === null) {
                return $done(null);
            }

            $state['waiting_for'] = $pending;
        }

        $now = Carbon::now()->getTimestamp();

        if (($state['answered'] ?? false) !== true && $now >= $state['since'] + $agentTimeoutSeconds) {
            return $done(self::agentTimeoutNote($agentTimeoutSeconds));
        }

        if ($now >= $state['since'] + $timeoutSeconds) {
            $waitingFor = is_string($state['waiting_for'] ?? null) ? " ({$state['waiting_for']})" : '';

            return $done('Windows setup did not finish within '.intdiv($timeoutSeconds, 60)." minutes{$waitingFor}: the server may still be setting itself up.");
        }

        return 5;
    }

    /**
     * What a Windows server is still waiting for, from the output of
     * WINDOWS_SETUP_COMMAND, or null once it is ready: Windows setup reports
     * IMAGE_STATE_COMPLETE (no image state: a template that was never
     * generalized), no new computer name waits for a restart, and, when
     * Cloudbase-Init is installed, its service has stopped and has recorded
     * every plugin of its configuration for this server's instance ID. A
     * stopped service alone proves nothing: it looks the same before it has
     * started and while the restart it asked for is still to come.
     *
     * A recorded plugin has run during this boot whatever its state: plugins
     * that run at every boot (ExtendVolumesPlugin, LocalScriptsPlugin,
     * MTUPlugin) always record 2, never 1. One that failed records nothing
     * (Cloudbase-Init logs the error and goes on), so it is still waited for.
     */
    public static function windowsSetupPending(string $output): ?string
    {
        $values = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim($value);
            }
        }

        $list = fn (string $key) => array_values(array_filter(array_map(trim(...), explode(',', $values[$key] ?? '')), fn (string $name) => $name !== ''));
        $image = $values['image'] ?? '';
        $service = $values['service'] ?? '';
        $name = $values['name'] ?? '';
        $active = $values['active_name'] ?? '';

        if (! in_array($image, ['', 'IMAGE_STATE_COMPLETE'], true)) {
            return "Windows setup reports {$image}";
        }

        if ($name !== '' && $active !== '' && strcasecmp($name, $active) !== 0) {
            return "the computer name {$name} takes effect at the next restart";
        }

        if ($service === '') {
            return null;
        }

        if ($service !== 'Stopped') {
            return "the cloudbase-init service is {$service}";
        }

        $recorded = array_map(strtolower(...), [...$list('done'), ...$list('later')]);
        $required = $list('plugins');

        // Without a plugins line Cloudbase-Init runs its default plugins: which it has run is unknown.
        if ($required === []) {
            return $recorded === [] ? 'Cloudbase-Init has not run for this server yet' : null;
        }

        $missing = array_values(array_filter($required, fn (string $plugin) => ! in_array(strtolower($plugin), $recorded, true)));

        return $missing === [] ? null : 'Cloudbase-Init has not finished '.implode(', ', $missing);
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
            GuestScriptEvent::Provisioned => ['reason' => self::provisionedReason($task), 'addresses' => $this->addresses->all($server)],
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
     * A custom script gets what triggered it and all addresses, and at an
     * event also that event's inputs (docs/adr/0043); never a password.
     *
     * @param  array<string, mixed>  $config  the VM's configuration
     * @return array<string, string|int|list<string>|null>
     */
    private function slotInputs(Server $server, OsPlatform $platform, ScriptSlot $slot, array $config, Task $task): array
    {
        if ($slot->custom === null) {
            return $slot->event === null ? [] : $this->inputs($server, $platform, $slot->event, $config, $task);
        }

        return ['trigger' => $slot->trigger, 'addresses' => $this->addresses->all($server)]
            + ($slot->event === null ? [] : $this->inputs($server, $platform, $slot->event, $config, $task));
    }

    /**
     * Why the post-install script runs: "create" (also a rebuild from
     * scratch), "reinstall", or "trial" for an administrator's trial run, so
     * one script can skip work that must happen only once per server.
     */
    private static function provisionedReason(?Task $task): string
    {
        return match ($task?->type) {
            TaskType::Reinstall => 'reinstall',
            TaskType::GuestScript => 'trial',
            default => 'create',
        };
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $inputs
     */
    private function runOnLinux(GuestAgentResource $agent, int $vmid, ScriptSlot $slot, string $name, string $script, array $inputs, ?string $input, bool $trace): GuestExecStatus
    {
        // A draft has its own path ($name ends in -draft), so boot units that
        // run the published script never pick it up; the inputs are the same.
        $path = '/usr/local/sbin/proxmotion-'.$name;
        $config = self::LINUX_CONFIG_DIR.'/'.$slot->file;

        $agent->runOrFail($vmid, ['mkdir', '-p', self::LINUX_CONFIG_DIR, '/usr/local/sbin']);
        $agent->writeFile($vmid, $config.'.json', self::json($inputs));
        $agent->writeFile($vmid, $config.'.vars', self::vars($inputs));
        $agent->writeFile($vmid, $path, $script);

        return $agent->run($vmid, $trace ? ['sh', '-x', $path] : ['sh', $path], $input, timeoutSeconds: $slot->timeoutSeconds());
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $inputs
     */
    private function runOnWindows(GuestAgentResource $agent, int $vmid, ScriptSlot $slot, string $name, string $script, array $inputs, ?string $input): GuestExecStatus
    {
        $path = self::WINDOWS_DIR.'\\'.$name.'.ps1';

        // cmd starts much faster than PowerShell; "already exists" fails harmlessly.
        $agent->run($vmid, ['cmd.exe', '/c', 'mkdir', self::WINDOWS_DIR]);
        $agent->writeFile($vmid, self::WINDOWS_DIR.'\\'.$slot->file.'.json', self::json($inputs));
        $agent->writeFile($vmid, $path, $script);

        return $agent->run($vmid, ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $path], $input, timeoutSeconds: $slot->timeoutSeconds());
    }

    private function startLog(Task $task, Server $server, ScriptSlot $slot, string $script, string $source, bool $trace): GuestScriptLog
    {
        $log = new GuestScriptLog;
        $log->forceFill([
            'server_id' => $server->id,
            'os_template_id' => $server->os_template_id,
            'task_id' => $task->id,
            'custom_script_id' => $slot->custom?->id,
            'script_name' => $slot->custom?->name,
            'event' => $slot->event,
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
    private function finishLog(GuestScriptLog $log, ScriptSlot $slot, GuestExecStatus $result): void
    {
        $keep = $slot->keepsOutput();
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

    private function failed(Task $task, GuestScriptLog $log, ScriptSlot $slot, Throwable $error, string $note): bool
    {
        if ($log->status === 'running') {
            $log->forceFill(['status' => 'failed', 'message' => $note, 'finished_at' => now()])->save();
        }

        if ($slot->isCritical()) {
            throw $error instanceof GuestScriptFailed || $error instanceof ProxmoxException ? $error : new GuestScriptFailed($note);
        }

        $task->completeStep($slot->step, $note);

        return false;
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
