<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Cluster;
use App\Proxmox\Data\Upid;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stops a Proxmox task an administrator cancelled, such as a running backup
 * (docs/adr/0051 §4). It holds the VM's lock until it ends, so the server's
 * next operation waits for it otherwise.
 */
class StopProxmoxTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    private const int RETRY_SECONDS = 30;

    public function __construct(public int $clusterId, public string $upid) {}

    public function handle(ProxmoxClientFactory $clients): void
    {
        $cluster = Cluster::query()->find($this->clusterId);

        if ($cluster === null) {
            return;
        }

        try {
            $clients->forCluster($cluster)->tasks()->stop(Upid::parse($this->upid));
        } catch (ProxmoxException) {
            // The node did not answer for a moment: try again later.
            if ($this->attempts() < $this->tries) {
                $this->release(self::RETRY_SECONDS);
            }
        }
    }
}
