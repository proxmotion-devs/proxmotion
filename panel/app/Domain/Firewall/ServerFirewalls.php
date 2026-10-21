<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Enums\OsPlatform;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\FirewallRuleset;
use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use Illuminate\Support\Facades\DB;

/**
 * Changes a server's firewall (docs/adr/0035): its rules are saved at once
 * and an apply_firewall task writes them to the VM firewall. Customers
 * (billing API) and administrators use the same paths.
 */
final readonly class ServerFirewalls
{
    public function __construct(
        private TaskRunner $tasks,
        private AppFirewallRules $apps,
    ) {}

    /**
     * Turn the firewall on or off and replace its rules. Turned on without
     * rules, the server gets the SSH or RDP rule of its platform.
     *
     * @param  list<FirewallRule>  $rules
     *
     * @throws ProvisioningException
     */
    public function change(Server $server, bool $enabled, array $rules, User|ApiToken $actor, ?string $ipAddress): Task
    {
        self::ensureChangeable($server);

        if (count($rules) > FirewallRule::MAX_RULES) {
            throw new ProvisioningException('A firewall can have at most :count rules.', ['count' => (string) FirewallRule::MAX_RULES]);
        }

        $before = $this->snapshot($server);

        if ($enabled && ! $server->firewall_enabled && $rules === []) {
            $rules = FirewallRule::defaultsFor($server->osTemplate?->platform);
        }

        $task = DB::transaction(function () use ($server, $enabled, $rules, $actor) {
            self::store($server, $enabled, $rules);

            // Refuses while another task runs, which rolls the change back.
            return $this->tasks->start($server, TaskType::ApplyFirewall, $actor, input: ['changing' => true]);
        });

        AuditLog::record('server.firewall_changed', $actor, $server, [
            'hostname' => $server->hostname,
            'before' => $before,
            'after' => $this->snapshot($server->refresh()),
        ], $ipAddress);

        return $task;
    }

    /**
     * Apply a rule set: its rules replace the server's ($mode "replace") or
     * follow them ("append"), and the firewall is turned on.
     *
     * @param  'replace'|'append'  $mode
     *
     * @throws ProvisioningException
     */
    public function applyRuleset(Server $server, FirewallRuleset $ruleset, string $mode, User|ApiToken $actor, ?string $ipAddress): Task
    {
        $current = $mode === 'append' ? $this->rules($server) : [];

        return $this->change($server, true, [...$current, ...$ruleset->firewallRules()], $actor, $ipAddress);
    }

    /**
     * Write the firewall again after the server's app ports changed (an app
     * uninstalled or upgraded), when it is on. Skipped while another task
     * runs or the server is not active: the firewall check reports it then.
     */
    public function refresh(Server $server): void
    {
        if (! $server->firewall_enabled || ! in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true)) {
            return;
        }

        try {
            $this->tasks->start($server, TaskType::ApplyFirewall);
        } catch (ProvisioningException) {
            // Another task runs.
        }
    }

    /**
     * The firewall of a new server of a package with a rule set: on, with its
     * rules. Part of creating the server, so no task of its own.
     */
    public static function startWith(Server $server, FirewallRuleset $ruleset): void
    {
        self::store($server, true, $ruleset->firewallRules());
    }

    /**
     * After a reinstall onto the other platform: a server whose only rule is
     * the untouched SSH or RDP rule gets the other one.
     *
     * @return string|null a note when its rules may no longer let anyone in (English key)
     */
    public static function followPlatform(Server $server): ?string
    {
        if (! $server->firewall_enabled) {
            return null;
        }

        $port = $server->osTemplate?->platform === OsPlatform::Windows ? 3389 : 22;
        $rules = $server->firewallRules()->get();

        if ($rules->contains(fn (ServerFirewallRule $rule) => $rule->toRule()->opens($port))) {
            return null;
        }

        if ($rules->count() === 1 && $rules->first()?->is_default === true) {
            self::store($server, true, FirewallRule::defaultsFor($server->osTemplate?->platform));

            return null;
        }

        return $port === 22
            ? 'The firewall keeps its rules, and none of them lets SSH (port 22) in.'
            : 'The firewall keeps its rules, and none of them lets RDP (port 3389) in.';
    }

    /**
     * @return list<FirewallRule>
     */
    public function rules(Server $server): array
    {
        return array_values($server->firewallRules()->get()->map(fn (ServerFirewallRule $rule) => $rule->toRule())->all());
    }

    /**
     * What the API and the pages show: on or off, the rules, and the app ports.
     *
     * @return array{enabled: bool, rules: list<array{action: string, protocol: string, port: string|null, source: string|null, comment: string|null}>, app_rules: list<array{app: string, protocol: string, port: int}>}
     */
    public function describe(Server $server): array
    {
        return [
            'enabled' => $server->firewall_enabled,
            'rules' => array_map(fn (FirewallRule $rule) => $rule->toArray(), $this->rules($server)),
            'app_rules' => $this->apps->for($server),
        ];
    }

    /**
     * @throws ProvisioningException
     */
    private static function ensureChangeable(Server $server): void
    {
        if (! in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true)) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }
    }

    /**
     * @param  list<FirewallRule>  $rules
     */
    private static function store(Server $server, bool $enabled, array $rules): void
    {
        $server->forceFill(['firewall_enabled' => $enabled])->save();
        $server->firewallRules()->delete();

        foreach ($rules as $position => $rule) {
            $model = new ServerFirewallRule;
            $model->forceFill([
                'server_id' => $server->id,
                'position' => $position,
                ...$rule->toArray(),
                'is_default' => $rule->isDefault,
            ])->save();
        }
    }

    /**
     * @return array{enabled: bool, rules: list<array<string, string|null>>}
     */
    private function snapshot(Server $server): array
    {
        return [
            'enabled' => $server->firewall_enabled,
            'rules' => array_map(fn (FirewallRule $rule) => $rule->toArray(), $this->rules($server)),
        ];
    }
}
