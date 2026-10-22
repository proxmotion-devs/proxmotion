<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Infrastructure\FirewallChecker;
use App\Models\Task;
use App\Provisioning\Network\ServerFirewall;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Writes a server's firewall as the panel records it (docs/adr/0035): the
 * panel's block of rules and the input policy. Nothing runs inside the
 * guest. Safe to run again.
 */
final readonly class FirewallPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private ServerFirewall $firewallRules,
        private FirewallChecker $firewall,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $qemu = $this->clients->forCluster($server->cluster)->qemu($server->node->name);

        $this->firewallRules->apply($qemu, $server, changing: ($task->input['changing'] ?? false) === true);

        // Read it back at once: a firewall drift closes now, not after the next sync.
        $this->firewall->recheck($server, $qemu);
        $task->completeStep('firewall');
    }
}
