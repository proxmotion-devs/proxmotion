<?php

declare(strict_types=1);

namespace App\Provisioning\Network;

use App\Domain\Firewall\AppFirewallRules;
use App\Enums\ServerOrigin;
use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Proxmox\Resources\QemuResource;

/**
 * Writes the panel's part of a server's VM firewall (docs/adr/0035 §2): the
 * isolation IP sets and rules (docs/adr/0034), the DHCP server rules
 * (docs/adr/0033), the app ports while it drops by default, the server's own
 * rules, and the input policy (docs/adr/0035). Anti-spoofing options and the
 * address IP set are QemuResource::enableAntiSpoofing()'s.
 */
final readonly class ServerFirewall
{
    public function __construct(
        private GuestIsolation $isolation,
        private AppFirewallRules $apps,
    ) {}

    /**
     * Adopted VMs get the panel's rules only when an administrator asks
     * ($always: Enable anti-spoofing, Apply again), when their firewall is
     * changed ($changing), when they have firewall rules or already have some
     * of the panel's; then later changes keep them right.
     *
     * @param  bool  $changing  a firewall change of the customer or an administrator: the policy follows it on adopted VMs too
     */
    public function apply(QemuResource $qemu, Server $server, bool $always = false, bool $changing = false): void
    {
        $writes = $always || $changing || $server->origin === ServerOrigin::Provisioned || $server->blocksByDefault() || $server->firewallRules()->exists()
            || QemuResource::panelRules($qemu->firewallRules($server->vmid)) !== [];

        if (! $writes) {
            return;
        }

        $sets = $this->isolation->sets($server);

        $qemu->applyIsolationSets($server->vmid, $sets);
        $qemu->applyRules($server->vmid, $this->rules($server, $sets));
        $qemu->removeUnusedIsolationSets($server->vmid, $sets);

        $policy = $this->inputPolicy($server, $changing);

        if ($policy !== null) {
            $qemu->setInputPolicy($server->vmid, $policy);
        }
    }

    /**
     * The panel's rules, top to bottom.
     *
     * @param  array<string, list<string>>  $sets  GuestIsolation::sets()
     * @return list<array<string, string|int>>
     */
    public function rules(Server $server, array $sets): array
    {
        $own = $server->firewallRules()->get()->flatMap(fn (ServerFirewallRule $rule) => $rule->toRule()->proxmoxRules());

        return [
            ...QemuResource::isolationRulesFor($sets),
            ...QemuResource::DHCP_SERVER_RULES,
            // The apps' ports matter only while everything else is dropped.
            ...($server->blocksByDefault() ? $this->apps->proxmoxRules($server) : []),
            ...$own->values()->all(),
        ];
    }

    /**
     * DROP when the server's default policy is drop; otherwise ACCEPT on
     * servers the panel created, and on adopted ones only when a firewall
     * change set it (otherwise they keep their own, docs/adr/0033 §2). Null:
     * leave it.
     *
     * @return 'ACCEPT'|'DROP'|null
     */
    public function inputPolicy(Server $server, bool $changing = false): ?string
    {
        return match (true) {
            $server->blocksByDefault() => 'DROP',
            $server->origin === ServerOrigin::Provisioned, $changing => 'ACCEPT',
            default => null,
        };
    }
}
