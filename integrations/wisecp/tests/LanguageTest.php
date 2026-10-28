<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Every text the module and config.php use exists in English and Turkish.
 */
final class LanguageTest extends TestCase
{
    public function test_english_and_turkish_have_the_same_keys(): void
    {
        $english = include PROXMOTION_WISECP_MODULE . '/lang/en.php';
        $turkish = include PROXMOTION_WISECP_MODULE . '/lang/tr.php';

        $this->assertSame([], array_diff(array_keys($english), array_keys($turkish)), 'Missing in tr.php');
        $this->assertSame([], array_diff(array_keys($turkish), array_keys($english)), 'Missing in en.php');
    }

    public function test_every_used_key_exists(): void
    {
        $english = include PROXMOTION_WISECP_MODULE . '/lang/en.php';
        $source = (string) file_get_contents(PROXMOTION_WISECP_MODULE . '/Proxmotion.php');
        $config = (string) file_get_contents(PROXMOTION_WISECP_MODULE . '/config.php');

        preg_match_all("/->text\\('([a-z_]+)'[,)]/", $source, $direct);
        preg_match_all("/\\{lang\\.([a-z0-9-]+)\\}/", $config, $fromConfig);
        $pages = file_get_contents(PROXMOTION_WISECP_MODULE . '/pages/tools/apps.php') . file_get_contents(PROXMOTION_WISECP_MODULE . '/pages/tools/firewall.php') . file_get_contents(PROXMOTION_WISECP_MODULE . '/pages/tools/ssh-keys.php') . file_get_contents(PROXMOTION_WISECP_MODULE . '/pages/tools/snapshots.php');
        preg_match_all("/\\\$t\\('([a-z_]+)'\\)/", $pages, $fromPage);

        // Keys built from a name: 'setting_' . $key, the busy/not_active choice and app statuses.
        $built = ['setting_package', 'setting_location', 'setting_os_template', 'setting_network_model', 'busy', 'not_active'];

        foreach (['queued', 'installing', 'installed', 'failed', 'uninstalling', 'uninstall_failed', 'removed'] as $status) {
            $built[] = 'app_status_' . $status;
        }

        foreach (['queued', 'running', 'succeeded', 'failed'] as $status) {
            $built[] = 'app_run_status_' . $status;
        }

        foreach (['cpu_cores', 'memory_gib', 'disk_gib', 'ipv4_count', 'ipv6_count', 'port_speed_mbps', 'traffic_gb'] as $resource) {
            $built[] = 'setting_' . $resource;
        }

        $used = array_unique(array_merge($direct[1], $fromConfig[1], $fromPage[1], $built));

        $this->assertNotEmpty($direct[1]);
        $this->assertNotEmpty($fromPage[1]);
        $this->assertSame([], array_values(array_diff($used, array_keys($english))));
    }
}
