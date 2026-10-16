<?php

namespace Tests\Integration;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Domain\Infrastructure\PermissionChecker;
use App\Models\OsTemplate;

/**
 * The test token set up as in docs/guides/real-proxmox-verification.md must
 * pass the panel's own permission check: nothing missing, nothing excess.
 * A failure here means either the guide or the checker is wrong.
 */
class PermissionTest extends IntegrationTestCase
{
    public function test_the_token_has_exactly_the_privileges_the_panel_asks_for()
    {
        // The sync records the Proxmox version (which agent privilege) and the templates.
        app(ClusterSynchronizer::class)->sync($this->cluster);

        // Only templates mapped to an OS template are checked for clone rights: map the test template.
        $template = $this->cluster->proxmoxTemplates()->where('vmid', $this->templateVmid())->first();
        $this->assertNotNull($template, 'The test template was not synced.');
        OsTemplate::factory()->create(['name' => 'Integration test OS'])->proxmoxTemplates()->attach($template);

        $results = app(PermissionChecker::class)->check($this->cluster->refresh());
        $this->assertNotEmpty($results);

        foreach ($results as $result) {
            $this->assertSame([], $result['missing'], "Missing on {$result['path']} ({$result['purpose']}).");
            $this->assertSame([], $result['excess'], "Excess on {$result['path']} ({$result['purpose']}).");
        }
    }

    public function test_the_guest_agent_privilege_matches_the_proxmox_version()
    {
        app(ClusterSynchronizer::class)->sync($this->cluster);
        $version = (string) $this->cluster->refresh()->pve_version;

        $vmPrivileges = collect(app(PermissionChecker::class)->check($this->cluster))
            ->firstWhere('path', "/pool/{$this->pool()}");
        $this->assertNotNull($vmPrivileges, 'No requirement for the pool path.');

        $granted = $this->client()->access()->privilegesOn("/pool/{$this->pool()}");
        $expected = version_compare($version, '9', '>=') ? 'VM.GuestAgent.Unrestricted' : 'VM.Monitor';

        $this->assertContains($expected, $granted, "Proxmox VE {$version} needs {$expected} for guest agent commands.");
    }
}
