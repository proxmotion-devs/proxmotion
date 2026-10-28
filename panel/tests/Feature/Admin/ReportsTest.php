<?php

namespace Tests\Feature\Admin;

use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportTable;
use App\Enums\AppInstallationStatus;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\UsageAction;
use App\Enums\UsageKind;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\Cluster;
use App\Models\Location;
use App\Models\Node;
use App\Models\Server;
use App\Models\Task;
use App\Models\TrafficPeriod;
use App\Models\UsageViolation;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;

/**
 * The reports section (docs/adr/0046).
 */
class ReportsTest extends AdminTestCase
{
    use BuildsInfrastructure;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->buildInfrastructure();
    }

    public function test_reports_open_on_the_capacity_report()
    {
        $this->get(route('admin.reports.index'))->assertRedirect(route('admin.reports.show', 'capacity'));

        foreach (['capacity', 'traffic', 'servers', 'operations'] as $report) {
            $this->get(route('admin.reports.show', $report))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('admin/reports/Show')->where('report', $report));
        }

        $this->get('/admin/reports/unknown')->assertNotFound();
    }

    public function test_guests_cannot_open_reports()
    {
        auth()->logout();

        $this->get(route('admin.reports.show', 'capacity'))->assertRedirect(route('login'));
        $this->get(route('admin.reports.csv', ['capacity', 'nodes']))->assertRedirect(route('login'));
    }

    public function test_capacity_counts_what_servers_book_on_nodes_storages_and_pools()
    {
        $server = $this->server();
        $this->server();
        $this->server(['status' => ServerStatus::Terminated]);
        $this->ipv4Pool->addresses()->orderBy('id')->firstOrFail()->forceFill(['server_id' => $server->id])->save();
        $addresses = $this->ipv4Pool->addresses()->count();

        // Another location's node is left out by the filter.
        $other = Location::factory()->create();
        Node::factory()->for(Cluster::factory()->for($other))->create(['name' => 'elsewhere']);

        $this->get(route('admin.reports.show', ['capacity', 'location' => $this->location->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.location', $this->location->uuid)
                ->where('tables.0.key', 'nodes')
                ->where('tables.0.row_count', 1)
                ->where('tables.0.rows.0.node', 'pve1')
                ->where('tables.0.rows.0.servers', 2)
                ->where('tables.0.rows.0.vcpu_booked', 4)
                ->where('tables.0.rows.0.memory_booked_mib', 8192)
                ->where('tables.0.rows.0.memory_capacity_mib', 65536)
                ->where('tables.1.key', 'storages')
                ->where('tables.1.rows.0.storage', 'local-lvm')
                ->where('tables.1.rows.0.booked_mib', 81920)
                ->where('tables.1.rows.0.size_mib', 512000)
                ->where('tables.2.key', 'ip_pools')
                ->where('tables.2.rows.0.addresses', $addresses)
                ->where('tables.2.rows.0.assigned', 1)
                ->where('tables.2.rows.0.free', $addresses - 1));

        $this->get(route('admin.reports.show', 'capacity'))
            ->assertInertia(fn (Assert $page) => $page->where('tables.0.row_count', 2));
    }

    public function test_capacity_shows_a_shared_storage_once_per_cluster()
    {
        $second = $this->addNode('pve2');

        foreach ([$this->node, $second] as $node) {
            $node->storages()->forceCreate([
                'name' => 'ceph', 'type' => 'rbd', 'content' => ['images'], 'is_shared' => true,
                'is_available' => true, 'use_for_servers' => true, 'total_bytes' => 1000 * 1024 ** 3, 'used_bytes' => 0,
            ]);
        }

        $this->server(['storage_id' => $this->node->storages()->where('name', 'ceph')->value('id')]);
        $this->server(['node_id' => $second->id, 'storage_id' => $second->storages()->where('name', 'ceph')->value('id')]);

        $this->get(route('admin.reports.show', 'capacity'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.1.row_count', 3)
                ->where('tables.1.rows', fn ($rows) => collect($rows)->where('storage', 'ceph')->count() === 1
                    && collect($rows)->firstWhere('storage', 'ceph')['booked_mib'] === 81920));
    }

    public function test_traffic_lists_servers_of_the_period_with_totals_and_violations()
    {
        $big = $this->server(['hostname' => 'big.example.com', 'traffic_bytes' => 10_000]);
        $small = $this->server(['hostname' => 'small.example.com']);
        TrafficPeriod::query()->insert([
            ['server_id' => $big->id, 'period_start' => '2026-10-01', 'in_bytes' => 3000, 'out_bytes' => 2000, 'created_at' => now(), 'updated_at' => now()],
            ['server_id' => $small->id, 'period_start' => '2026-10-01', 'in_bytes' => 100, 'out_bytes' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['server_id' => $small->id, 'period_start' => '2026-09-01', 'in_bytes' => 7, 'out_bytes' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $violation = new UsageViolation;
        $violation->forceFill([
            'server_id' => $big->id, 'kind' => UsageKind::Traffic, 'period_start' => '2026-10-01',
            'value' => 5000, 'threshold' => 4000, 'action' => UsageAction::None,
        ])->save();

        $this->get(route('admin.reports.show', 'traffic'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', '2026-10-01')
                ->has('filterOptions.period.options', 12)
                ->where('tables.0.rows.0.server', 'big.example.com')
                ->where('tables.0.rows.0.server_uuid', $big->uuid)
                ->where('tables.0.rows.0.counted_bytes', 5000)
                ->where('tables.0.rows.0.quota_bytes', 10_000)
                ->where('tables.0.rows.1.server', 'small.example.com')
                ->where('tables.0.totals.counted_bytes', 5150)
                ->where('tables.1.key', 'locations')
                ->where('tables.1.rows.0.servers', 2)
                ->where('tables.1.rows.0.in_bytes', 3100)
                ->where('tables.4.key', 'violations')
                ->where('tables.4.rows.0.server', 'big.example.com')
                ->where('tables.4.rows.0.unit', 'bytes'));

        $this->get(route('admin.reports.show', ['traffic', 'period' => '2026-09-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.0.row_count', 1)
                ->where('tables.0.rows.0.in_bytes', 7)
                ->where('tables.4.row_count', 0));

        $this->get(route('admin.reports.show', ['traffic', 'period' => '2020-01-01']))->assertSessionHasErrors('period');
    }

    public function test_server_inventory_groups_servers_and_counts_them_per_month()
    {
        $this->server();
        $this->server(['status' => ServerStatus::Suspended]);
        $this->server(['origin' => ServerOrigin::Adopted]);
        $this->server(['status' => ServerStatus::Terminated, 'terminated_at' => now()]);
        Carbon::setTestNow('2026-08-10 12:00:00');
        $this->server(['status' => ServerStatus::Terminated, 'terminated_at' => now()->addDay()]);
        Carbon::setTestNow('2026-10-15 12:00:00');

        $this->get(route('admin.reports.show', 'servers'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.0.key', 'statuses')
                ->where('tables.0.rows.0.status', 'active')
                ->where('tables.0.rows.0.servers', 2)
                ->where('tables.0.rows.1.status', 'suspended')
                ->where('tables.0.totals.servers', 3)
                ->where('tables.1.key', 'locations')
                ->where('tables.1.rows.0.active', 2)
                ->where('tables.1.rows.0.suspended', 1)
                ->where('tables.5.key', 'months')
                ->has('tables.5.rows', 12)
                ->where('tables.5.rows.9', ['month' => '2026-08', 'created' => 1, 'adopted' => 0, 'terminated' => 1, 'net' => 0])
                ->where('tables.5.rows.11', ['month' => '2026-10', 'created' => 3, 'adopted' => 1, 'terminated' => 1, 'net' => 3]));
    }

    public function test_operations_counts_tasks_and_apps_of_the_period()
    {
        $server = $this->server(['hostname' => 'web.example.com']);

        foreach ([60, 120, 300] as $seconds) {
            $this->task($server, TaskType::Create, TaskStatus::Succeeded, ['started_at' => now()->subSeconds($seconds), 'finished_at' => now()]);
        }

        $this->task($server, TaskType::Create, TaskStatus::Failed, ['user_id' => $this->admin->id, 'error' => '=Clone failed', 'finished_at' => now()]);
        $this->task($server, TaskType::Reboot, TaskStatus::Running);
        Carbon::setTestNow('2026-08-01 12:00:00');
        $this->task($server, TaskType::Reboot, TaskStatus::Failed);
        Carbon::setTestNow('2026-10-15 12:00:00');

        $app = AppRecipe::factory()->create(['name' => 'WireGuard']);
        AppInstallation::factory()->for($server)->for($app, 'recipe')->create();
        AppInstallation::factory()->for($server)->for($app, 'recipe')->create(['status' => AppInstallationStatus::Failed]);
        AppInstallation::factory()->for($server)->for($app, 'recipe')->create(['is_trial' => true]);

        $this->get(route('admin.reports.show', 'operations'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.days', 30)
                ->where('tables.0.rows.0.type', 'create')
                ->where('tables.0.rows.0.tasks', 4)
                ->where('tables.0.rows.0.succeeded', 3)
                ->where('tables.0.rows.0.failed', 1)
                ->where('tables.0.rows.0.median_seconds', 120)
                ->where('tables.0.rows.1.type', 'reboot')
                ->where('tables.0.rows.1.open', 1)
                ->where('tables.1.row_count', 1)
                ->where('tables.1.rows.0.server', 'web.example.com')
                ->where('tables.1.rows.0.started_by', $this->admin->name)
                ->where('tables.2.rows.0.app', 'WireGuard')
                ->where('tables.2.rows.0.installations', 2)
                ->where('tables.2.rows.0.installed', 1)
                ->where('tables.2.rows.0.failed', 1));

        $this->get(route('admin.reports.show', ['operations', 'days' => 90]))
            ->assertInertia(fn (Assert $page) => $page->where('tables.1.row_count', 2));

        $this->get(route('admin.reports.show', ['operations', 'days' => 5]))->assertSessionHasErrors('days');
    }

    public function test_tables_export_as_csv_with_the_page_filters()
    {
        $server = $this->server(['hostname' => 'web.example.com']);
        $this->task($server, TaskType::Create, TaskStatus::Failed, ['error' => '=HYPERLINK("x")', 'finished_at' => now()]);

        $response = $this->get(route('admin.reports.csv', ['operations', 'failed_tasks', 'days' => 7]))
            ->assertOk()
            ->assertDownload('operations-failed_tasks-2026-10-15.csv');

        $lines = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
        $this->assertSame(['finished_at', 'server', 'type', 'started_by', 'error'], $lines[0]);
        $this->assertSame('web.example.com', $lines[1][1]);
        $this->assertSame('create', $lines[1][2]);
        // Read as text by spreadsheets, never as a formula.
        $this->assertSame('\'=HYPERLINK("x")', $lines[1][4]);

        $this->get(route('admin.reports.csv', ['capacity', 'nodes']))->assertOk();
        $this->get(route('admin.reports.csv', ['capacity', 'unknown']))->assertNotFound();
        $this->get(route('admin.reports.csv', ['operations', 'apps', 'days' => 5]))->assertSessionHasErrors('days');
    }

    public function test_pages_show_at_most_a_page_of_rows()
    {
        $table = new ReportTable('t', 'T', null, [new ReportColumn('n', 'N', 'number')], array_map(fn (int $n) => ['n' => $n], range(1, 150)));

        $this->assertCount(ReportTable::SHOWN_ROWS, $table->toPayload()['rows']);
        $this->assertSame(150, $table->toPayload()['row_count']);
        $this->assertCount(151, iterator_to_array($table->csvLines(), false));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function server(array $attributes = []): Server
    {
        return Server::factory()->for($this->node)->create([
            'package_id' => $this->package->id,
            'storage_id' => $this->storage->id,
            'billing_system_id' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(Server $server, TaskType $type, TaskStatus $status, array $attributes = []): Task
    {
        $task = new Task;
        $task->forceFill(['server_id' => $server->id, 'type' => $type, 'status' => $status, 'steps' => [], ...$attributes])->save();

        return $task;
    }
}
