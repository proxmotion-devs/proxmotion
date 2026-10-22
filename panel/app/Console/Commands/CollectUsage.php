<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Usage\UsageCollector;
use App\Domain\Usage\UsageLimits;
use App\Models\Cluster;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('usage:collect')]
#[Description('Count the traffic of every server and act on traffic quotas and usage limits (docs/adr/0039)')]
class CollectUsage extends Command
{
    public function handle(UsageCollector $collector, UsageLimits $limits): int
    {
        $failed = false;

        foreach (Cluster::query()->where('is_active', true)->get() as $cluster) {
            try {
                $servers = $collector->collect($cluster);
            } catch (ProxmoxException $e) {
                // Skipped this time: the next collection counts the traffic in between.
                $failed = true;
                $this->components->error("{$cluster->name}: {$e->getMessage()}");

                continue;
            }

            $limits->check($servers);
            $this->components->info("{$cluster->name}: sampled {$servers->count()} servers.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
