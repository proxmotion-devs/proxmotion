<?php

namespace Tests\Integration;

use App\Domain\Infrastructure\ClusterSynchronizer;

/**
 * Read-only checks: the panel reaches the test Proxmox with the token and
 * understands what it answers. Creates nothing.
 */
class ConnectionTest extends IntegrationTestCase
{
    public function test_the_api_answers_with_a_version()
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $this->client()->version());
    }

    public function test_the_sync_mirrors_the_test_node_storage_bridge_and_template()
    {
        app(ClusterSynchronizer::class)->sync($this->cluster);

        $cluster = $this->cluster->refresh();
        $this->assertNull($cluster->last_sync_error);
        $this->assertNotNull($cluster->pve_version);

        $node = $cluster->nodes()->where('name', $this->node())->first();
        $this->assertNotNull($node, "Node {$this->node()} was not synced.");
        $this->assertTrue($node->storages()->where('name', $this->setting('PROXMOX_TEST_STORAGE'))->exists(), 'The test storage was not synced.');
        $this->assertTrue($node->bridges()->where('name', $this->setting('PROXMOX_TEST_BRIDGE'))->exists(), 'The test bridge was not synced.');
        $this->assertTrue($cluster->proxmoxTemplates()->where('vmid', $this->templateVmid())->exists(), 'The test template was not synced as a template.');
    }

    /**
     * Sync, adoption and the drift report read these fields of /cluster/resources.
     */
    public function test_cluster_resources_have_the_fields_the_panel_reads()
    {
        $raw = $this->client()->get('/cluster/resources', ['type' => 'vm']);
        $this->assertIsArray($raw);

        $template = collect($raw)->first(fn ($guest) => is_array($guest) && (int) ($guest['vmid'] ?? 0) === $this->templateVmid());
        $this->assertIsArray($template, 'The test template is not in /cluster/resources.');

        foreach (['vmid', 'node', 'type', 'template', 'status', 'maxcpu', 'maxmem', 'maxdisk'] as $field) {
            $this->assertArrayHasKey($field, $template, "/cluster/resources has no '{$field}' field.");
        }

        $this->assertSame($this->pool(), $template['pool'] ?? null, 'The test template must be in the test pool.');

        $guest = $this->guest($this->templateVmid());
        $this->assertNotNull($guest);
        $this->assertTrue($guest->template);
        $this->assertSame('qemu', $guest->type);
        $this->assertNotNull($guest->cpus);
        $this->assertNotNull($guest->memoryBytes);
        $this->assertNotNull($guest->diskBytes);
    }

    /**
     * TaskWaiter::waitForVm() lists running tasks of a guest.
     */
    public function test_running_tasks_of_a_guest_can_be_listed()
    {
        $this->assertIsArray($this->client()->tasks()->activeForVm($this->node(), $this->templateVmid()));
    }
}
