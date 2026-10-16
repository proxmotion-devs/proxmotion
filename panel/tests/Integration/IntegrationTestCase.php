<?php

namespace Tests\Integration;

use App\Domain\Infrastructure\PermissionChecker;
use App\Models\Cluster;
use App\Proxmox\Data\VmResource;
use App\Proxmox\ProxmoxClient;
use App\Proxmox\ProxmoxClientFactory;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Tests against a real TEST Proxmox VE (docs/guides/real-proxmox-verification.md).
 *
 * They are skipped unless run with `composer test:integration`
 * (PROXMOX_INTEGRATION=1) and configured in .env.integration, so the normal
 * test run never talks to a server. Safety rules:
 *  - the token must not be root-equivalent or manage VMs outside the pool;
 *  - tests only create VMs with VMIDs from PROXMOX_TEST_VMID_FROM up, in the pool;
 *  - VMs registered with trackVm() are deleted in tearDown, also after failures,
 *    and only if they are still in that range and in the pool.
 */
abstract class IntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED = [
        'PROXMOX_TEST_HOST',
        'PROXMOX_TEST_TOKEN_ID',
        'PROXMOX_TEST_TOKEN_SECRET',
        'PROXMOX_TEST_NODE',
        'PROXMOX_TEST_STORAGE',
        'PROXMOX_TEST_BRIDGE',
        'PROXMOX_TEST_TEMPLATE_VMID',
        'PROXMOX_TEST_VMID_FROM',
    ];

    private const MIN_VMID_FROM = 1000;

    protected Cluster $cluster;

    /** @var array<string, string> */
    private array $settings = [];

    /** @var list<int> */
    private array $trackedVmids = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::settingFromProcess('PROXMOX_INTEGRATION') !== '1') {
            $this->markTestSkipped('Integration tests only run with `composer test:integration`.');
        }

        $this->settings = self::loadSettings();
        $missing = array_values(array_filter(self::REQUIRED, fn (string $key) => ($this->settings[$key] ?? '') === ''));

        if ($missing !== []) {
            $this->markTestSkipped('Set '.implode(', ', $missing).' in .env.integration (see .env.integration.example).');
        }

        if ($this->vmidFrom() < self::MIN_VMID_FROM) {
            $this->fail('PROXMOX_TEST_VMID_FROM must be at least '.self::MIN_VMID_FROM.'.');
        }

        $this->cluster = Cluster::factory()->create([
            'hosts' => array_map(trim(...), explode(',', $this->setting('PROXMOX_TEST_HOST'))),
            'port' => (int) ($this->settings['PROXMOX_TEST_PORT'] ?? 8006),
            'token_id' => $this->setting('PROXMOX_TEST_TOKEN_ID'),
            'token_secret' => $this->setting('PROXMOX_TEST_TOKEN_SECRET'),
            'verify_tls' => filter_var($this->settings['PROXMOX_TEST_VERIFY_TLS'] ?? 'true', FILTER_VALIDATE_BOOL),
            'resource_pool' => $this->pool(),
        ]);

        $this->refuseRootEquivalentTokens();
    }

    protected function tearDown(): void
    {
        $problems = [];

        foreach (array_reverse($this->trackedVmids) as $vmid) {
            try {
                $this->deleteTestVm($vmid);
            } catch (Throwable $e) {
                $problems[] = "VM {$vmid}: {$e->getMessage()}";
            }
        }

        $this->trackedVmids = [];
        parent::tearDown();

        if ($problems !== []) {
            $this->fail("Cleanup failed; delete these VMs by hand:\n".implode("\n", $problems));
        }
    }

    protected function client(): ProxmoxClient
    {
        return app(ProxmoxClientFactory::class)->forCluster($this->cluster);
    }

    protected function setting(string $key): string
    {
        return $this->settings[$key] ?? '';
    }

    protected function node(): string
    {
        return $this->setting('PROXMOX_TEST_NODE');
    }

    protected function pool(): string
    {
        return ($this->settings['PROXMOX_TEST_POOL'] ?? '') !== '' ? $this->settings['PROXMOX_TEST_POOL'] : 'proxmotion-test';
    }

    protected function templateVmid(): int
    {
        return (int) $this->setting('PROXMOX_TEST_TEMPLATE_VMID');
    }

    protected function vmidFrom(): int
    {
        return (int) $this->setting('PROXMOX_TEST_VMID_FROM');
    }

    /**
     * The guest of the cluster with this VMID, if any.
     */
    protected function guest(int $vmid): ?VmResource
    {
        foreach ($this->client()->cluster()->guests() as $guest) {
            if ($guest->vmid === $vmid) {
                return $guest;
            }
        }

        return null;
    }

    /**
     * Register a VM this test creates, so tearDown deletes it.
     */
    protected function trackVm(int $vmid): void
    {
        $this->assertGreaterThanOrEqual($this->vmidFrom(), $vmid, 'Integration tests only use VMIDs from PROXMOX_TEST_VMID_FROM up.');
        $this->trackedVmids[] = $vmid;
    }

    /**
     * Stop and delete a test VM, but only inside the VMID range and the pool.
     */
    private function deleteTestVm(int $vmid): void
    {
        $guest = $this->guest($vmid);

        if ($guest === null) {
            return;
        }

        if ($vmid < $this->vmidFrom() || $guest->pool !== $this->pool() || $guest->template) {
            throw new RuntimeException("not deleted: outside the test VMID range or the {$this->pool()} pool, or a template");
        }

        $client = $this->client();
        $qemu = $client->qemu($guest->node);
        $client->taskWaiter()->waitForVm($guest->node, $vmid);

        if ($qemu->status($vmid)['status'] === 'running') {
            $client->taskWaiter()->wait($qemu->power($vmid, 'stop'), timeoutSeconds: 120);
        }

        $client->taskWaiter()->wait($qemu->destroy($vmid), timeoutSeconds: 600);
    }

    /**
     * A token that could take over the installation, or touch VMs outside
     * the pool, is not used for tests that create and delete VMs.
     */
    private function refuseRootEquivalentTokens(): void
    {
        $access = $this->client()->access();
        $dangerous = array_values(array_intersect(PermissionChecker::DANGEROUS_PRIVILEGES, $access->privilegesOn('/')));
        $outsidePool = array_values(array_intersect(['VM.Allocate', 'VM.PowerMgmt', 'VM.Config.Disk'], $access->privilegesOn('/vms')));

        if ($dangerous !== [] || $outsidePool !== []) {
            $this->fail('Refusing to run: the test token has '.implode(', ', [...$dangerous, ...$outsidePool])
                .'. Give it only the roles from docs/guides/real-proxmox-verification.md.');
        }
    }

    /**
     * .env.integration values, falling back to the process environment.
     *
     * @return array<string, string>
     */
    private static function loadSettings(): array
    {
        $file = base_path('.env.integration');
        $fromFile = is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];
        $settings = [];

        foreach ([...self::REQUIRED, 'PROXMOX_TEST_PORT', 'PROXMOX_TEST_VERIFY_TLS', 'PROXMOX_TEST_POOL'] as $key) {
            $value = $fromFile[$key] ?? self::settingFromProcess($key);

            if (is_string($value)) {
                $settings[$key] = trim($value);
            }
        }

        return $settings;
    }

    private static function settingFromProcess(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) ? $value : (is_string($_SERVER[$key] ?? null) ? $_SERVER[$key] : null);
    }
}
