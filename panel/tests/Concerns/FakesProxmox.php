<?php

namespace Tests\Concerns;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the Proxmox API with the JSON fixtures in tests/Fixtures/proxmox.
 */
trait FakesProxmox
{
    /**
     * @param  array<string, mixed>  $overrides  extra or replacement URL patterns (matched first)
     */
    protected function fakeProxmox(array $overrides = []): Factory
    {
        // Array union: overrides win on identical patterns and are matched first otherwise.
        return Http::preventStrayRequests()->fake($overrides + [
            '*/api2/json/version' => $this->proxmoxFixture('version'),
            '*/api2/json/cluster/status' => $this->proxmoxFixture('cluster-status'),
            '*/api2/json/nodes' => $this->proxmoxFixture('nodes'),
            '*/api2/json/cluster/resources?type=storage' => $this->proxmoxFixture('cluster-resources-storage'),
            '*/api2/json/cluster/resources?type=vm' => $this->proxmoxFixture('cluster-resources-vm'),
            '*/api2/json/nodes/*/network?type=any_bridge' => $this->proxmoxFixture('node-network-bridges'),
            '*/api2/json/cluster/firewall/options' => $this->proxmoxFixture('cluster-firewall-options'),
            // Storage health (docs/adr/0047).
            '*/api2/json/storage' => $this->proxmoxFixture('storage'),
            '*/api2/json/cluster/ceph/status' => $this->proxmoxFixture('cluster-ceph-status'),
            '*/api2/json/nodes/*/disks/list' => $this->proxmoxFixture('node-disks'),
            '*/api2/json/nodes/*/disks/zfs' => $this->proxmoxFixture('node-zfs'),
            '*/api2/json/nodes/*/disks/lvmthin' => $this->proxmoxFixture('node-lvmthin'),
        ]);
    }

    protected function proxmoxFixture(string $name, int $status = 200): PromiseInterface
    {
        $json = file_get_contents(base_path("tests/Fixtures/proxmox/{$name}.json"));

        return Http::response((string) $json, $status, ['Content-Type' => 'application/json']);
    }
}
