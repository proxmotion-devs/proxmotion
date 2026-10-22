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
 * Changes a server's firewall (docs/adr/0035): its default policy and rules
 * are saved at once and an apply_firewall task writes them to the VM
 * firewall. Customers (billing API) and administrators use the same paths.
 */
final readonly class ServerFirewalls
{
    public const POLICIES = ['accept', 'drop'];

    public function __construct(
        private TaskRunner $tasks,
        private AppFirewallRules $apps,
    ) {}

    /**
     * Set the default policy and replace the rules. A server that starts
     * dropping by default without a rule that allows anything gets the SSH
     * or RDP rule of its platform, so nobody is locked out by accident.
     *
     * @param  'accept'|'drop'  $policy  what happens to incoming traffic no rule matches
     * @param  list<FirewallRule>  $rules
     *
     * @throws ProvisioningException
     */
    public function change(Server $server, string $policy, array $rules, User|ApiToken $actor, ?string $ipAddress): Task
    {
        self::ensureChangeable($server);

        if ($policy === 'drop' && ! $server->blocksByDefault() && ! self::allowsAnything($rules)) {
            $rules = [...$rules, ...FirewallRule::defaultsFor($server->osTemplate?->platform)];
        }

        if (count($rules) > FirewallRule::MAX_RULES) {
            throw new ProvisioningException('A firewall can have at most :count rules.', ['count' => (string) FirewallRule::MAX_RULES]);
        }

        $before = $this->snapshot($server);

        $task = DB::transaction(function () use ($server, $policy, $rules, $actor) {
            self::store($server, $policy, $rules);

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
     * Apply a rule set: its rules and default policy replace the server's
     * ($mode "replace"), or its rules follow the server's ("append"; the
     * server keeps its default policy).
     *
     * @param  'replace'|'append'  $mode
     *
     * @throws ProvisioningException
     */
    public function applyRuleset(Server $server, FirewallRuleset $ruleset, string $mode, User|ApiToken $actor, ?string $ipAddress): Task
    {
        return $mode === 'append'
            ? $this->change($server, $server->firewall_policy === 'drop' ? 'drop' : 'accept', [...$this->rules($server), ...$ruleset->firewallRules()], $actor, $ipAddress)
            : $this->change($server, $ruleset->default_policy === 'accept' ? 'accept' : 'drop', $ruleset->firewallRules(), $actor, $ipAddress);
    }

    /**
     * Write the firewall again after the server's app ports changed (an app
     * uninstalled or upgraded), when they matter: while it drops by default.
     * Skipped while another task runs or the server is not active: the
     * firewall check reports it then.
     */
    public function refresh(Server $server): void
    {
        if (! $server->blocksByDefault() || ! in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true)) {
            return;
        }

        try {
            $this->tasks->start($server, TaskType::ApplyFirewall);
        } catch (ProvisioningException) {
            // Another task runs.
        }
    }

    /**
     * The firewall of a new server of a package with a rule set: its rules
     * and default policy. Part of creating the server, so no task of its own.
     */
    public static function startWith(Server $server, FirewallRuleset $ruleset): void
    {
        self::store($server, $ruleset->default_policy === 'accept' ? 'accept' : 'drop', $ruleset->firewallRules());
    }

    /**
     * After a reinstall onto the other platform: a server dropping by default
     * whose only rule is the untouched SSH or RDP rule gets the other one.
     *
     * @return string|null a note when its rules may no longer let anyone in (English key)
     */
    public static function followPlatform(Server $server): ?string
    {
        if (! $server->blocksByDefault()) {
            return null;
        }

        $port = $server->osTemplate?->platform === OsPlatform::Windows ? 3389 : 22;
        $rules = $server->firewallRules()->get();

        if ($rules->contains(fn (ServerFirewallRule $rule) => $rule->toRule()->opens($port))) {
            return null;
        }

        if ($rules->count() === 1 && $rules->first()?->is_default === true) {
            self::store($server, 'drop', FirewallRule::defaultsFor($server->osTemplate?->platform));

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
     * What the API and the pages show: the default policy, the rules, and the
     * app ports (opened while the server drops by default).
     *
     * @return array{default_policy: string, rules: list<array{action: string, protocol: string, port: string|null, source: string|null, comment: string|null}>, app_rules: list<array{app: string, protocol: string, port: int}>}
     */
    public function describe(Server $server): array
    {
        return [
            'default_policy' => $server->blocksByDefault() ? 'drop' : 'accept',
            'rules' => array_map(fn (FirewallRule $rule) => $rule->toArray(), $this->rules($server)),
            'app_rules' => $this->apps->for($server),
        ];
    }

    /**
     * @param  list<FirewallRule>  $rules
     */
    private static function allowsAnything(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule->action === 'accept') {
                return true;
            }
        }

        return false;
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
    private static function store(Server $server, string $policy, array $rules): void
    {
        $server->forceFill(['firewall_policy' => $policy])->save();
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
     * @return array{default_policy: string, rules: list<array<string, string|null>>}
     */
    private function snapshot(Server $server): array
    {
        return [
            'default_policy' => $server->blocksByDefault() ? 'drop' : 'accept',
            'rules' => array_map(fn (FirewallRule $rule) => $rule->toArray(), $this->rules($server)),
        ];
    }
}
