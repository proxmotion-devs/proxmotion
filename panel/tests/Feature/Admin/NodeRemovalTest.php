<?php

namespace Tests\Feature\Admin;

use App\Enums\NodeStatus;
use App\Enums\ServerStatus;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Node;
use App\Models\Server;

/**
 * Removing nodes Proxmox no longer reports from the panel (docs/adr/0057).
 */
class NodeRemovalTest extends AdminTestCase
{
    private function goneNode(?Location $location = null): Node
    {
        $node = Node::factory()
            ->for(Cluster::factory()->for($location ?? Location::factory()))
            ->create(['name' => 'old-node', 'status' => NodeStatus::Unknown]);
        $node->bridges()->forceCreate(['name' => 'vmbr0', 'is_active' => false, 'cidr' => '203.0.113.11/24']);

        return $node;
    }

    public function test_a_node_proxmox_still_reports_cannot_be_removed()
    {
        foreach ([NodeStatus::Online, NodeStatus::Offline] as $status) {
            $node = Node::factory()->create(['status' => $status]);

            $this->delete(route('admin.nodes.destroy', $node))->assertRedirect();

            $this->assertNotSoftDeleted($node);
        }

        $this->assertFalse(AuditLog::query()->where('action', 'node.removed')->exists());
    }

    public function test_a_node_with_servers_left_on_it_cannot_be_removed()
    {
        $node = $this->goneNode();
        Server::factory()->for($node)->create(['status' => ServerStatus::Active]);

        $this->delete(route('admin.nodes.destroy', $node))->assertRedirect();

        $this->assertNotSoftDeleted($node);
    }

    public function test_a_gone_node_is_removed_and_its_terminated_servers_keep_it()
    {
        $location = Location::factory()->create();
        $node = $this->goneNode($location);
        $server = Server::factory()->for($node)->create(['status' => ServerStatus::Terminated]);
        $pool = IpPool::factory()->for($location)->create();
        $address = $pool->addresses()->create(['address' => '203.0.113.11']);
        $address->forceFill(['is_reserved' => true, 'notes' => 'Proxmox node'])->save();

        $this->delete(route('admin.nodes.destroy', $node))->assertRedirect();

        $this->assertSoftDeleted($node);
        $this->assertFalse(Node::query()->whereKey($node->id)->exists());
        $this->assertTrue($server->refresh()->node->is($node));
        $this->assertTrue(AuditLog::query()->where('action', 'node.removed')->where('properties->name', 'old-node')->exists());

        // The sync reserved its address; no other node of the location has it.
        $address->refresh();
        $this->assertFalse($address->is_reserved);
        $this->assertNull($address->notes);
    }

    public function test_an_address_another_node_still_has_stays_reserved()
    {
        $location = Location::factory()->create();
        $node = $this->goneNode($location);
        $other = Node::factory()->for(Cluster::factory()->for($location))->create();
        $other->bridges()->forceCreate(['name' => 'vmbr0', 'is_active' => true, 'cidr' => '203.0.113.11/24']);
        $address = IpPool::factory()->for($location)->create()->addresses()->create(['address' => '203.0.113.11']);
        $address->forceFill(['is_reserved' => true, 'notes' => 'Proxmox node'])->save();

        $this->delete(route('admin.nodes.destroy', $node))->assertRedirect();

        $this->assertSoftDeleted($node);
        $this->assertTrue($address->refresh()->is_reserved);
    }

    public function test_the_cluster_page_offers_removal_only_for_gone_nodes()
    {
        $node = $this->goneNode();
        Node::factory()->for($node->cluster)->create(['name' => 'pve1']);

        $this->get(route('admin.clusters.show', $node->cluster))
            ->assertInertia(fn ($page) => $page
                ->has('nodes', 2)
                ->where('nodes.0.name', 'old-node')
                ->where('nodes.0.status', NodeStatus::Unknown->value));

        $this->delete(route('admin.nodes.destroy', $node));

        $this->get(route('admin.clusters.show', $node->cluster))
            ->assertInertia(fn ($page) => $page->has('nodes', 1)->where('nodes.0.name', 'pve1'));
    }
}
