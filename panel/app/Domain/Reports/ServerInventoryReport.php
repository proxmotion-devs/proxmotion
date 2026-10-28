<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Models\Server;
use Carbon\CarbonImmutable;

/**
 * The servers there are (every one not terminated) by status, location,
 * package, OS template and billing system, and how many were created,
 * adopted and terminated in each of the last twelve months.
 */
final class ServerInventoryReport implements Report
{
    use FiltersByLocation;

    private const int MONTHS = 12;

    public function rules(): array
    {
        return $this->locationRules();
    }

    public function filters(array $input): array
    {
        return ['location' => $input['location'] ?? null];
    }

    public function filterOptions(): array
    {
        return ['location' => $this->locationOptions()];
    }

    public function tables(array $filters): array
    {
        $locationId = $this->locationId($filters['location']);

        $rows = array_values(Server::query()->occupying()
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->with(['location:id,name', 'package:id,name', 'osTemplate:id,name', 'billingSystem:id,name'])
            ->get(['id', 'status', 'location_id', 'package_id', 'os_template_id', 'billing_system_id', 'cpu_cores', 'memory_mib', 'disk_mib'])
            ->map(fn (Server $server) => [
                'status' => $server->status->value,
                'location' => $server->location->name,
                'package' => $server->package->name ?? __('No package'),
                'os_template' => $server->osTemplate->name ?? __('None'),
                'billing_system' => $server->billingSystem->name ?? __('None'),
                'active' => $server->status === ServerStatus::Active ? 1 : 0,
                'suspended' => $server->status === ServerStatus::Suspended ? 1 : 0,
                'cpu_cores' => $server->cpu_cores,
                'memory_mib' => $server->memory_mib,
                'disk_mib' => $server->disk_mib,
            ])
            ->all());

        $sums = ['active', 'suspended', 'cpu_cores', 'memory_mib', 'disk_mib'];
        $columns = fn (string $key, string $label) => [
            new ReportColumn($key, $label),
            new ReportColumn('servers', __('Servers'), 'number'),
            new ReportColumn('active', __('Active'), 'number'),
            new ReportColumn('suspended', __('Suspended'), 'number'),
            new ReportColumn('cpu_cores', __('vCPU'), 'number'),
            new ReportColumn('memory_mib', __('Memory'), 'mib'),
            new ReportColumn('disk_mib', __('Disk'), 'mib'),
        ];
        $grouped = fn (string $key, string $title, string $label) => new ReportTable(
            $key === 'os_template' ? 'os_templates' : "{$key}s",
            $title,
            null,
            $columns($key, $label),
            Reports::group($rows, $key, fn (array $row) => (string) $row[$key], 'servers', $sums),
        );

        return [
            new ReportTable(
                'statuses',
                __('By status'),
                __('Every server that is not deleted.'),
                [
                    new ReportColumn('status', __('Status'), 'server_status'),
                    new ReportColumn('servers', __('Servers'), 'number'),
                    new ReportColumn('cpu_cores', __('vCPU'), 'number'),
                    new ReportColumn('memory_mib', __('Memory'), 'mib'),
                    new ReportColumn('disk_mib', __('Disk'), 'mib'),
                ],
                array_map(
                    fn (array $row) => array_diff_key($row, ['active' => true, 'suspended' => true]),
                    Reports::group($rows, 'status', fn (array $row) => (string) $row['status'], 'servers', $sums),
                ),
                $rows === [] ? null : [
                    'status' => __('Total'),
                    'servers' => count($rows),
                    'cpu_cores' => Reports::sum($rows, 'cpu_cores'),
                    'memory_mib' => Reports::sum($rows, 'memory_mib'),
                    'disk_mib' => Reports::sum($rows, 'disk_mib'),
                ],
            ),
            $grouped('location', __('By location'), __('Location')),
            $grouped('package', __('By package'), __('Package')),
            $grouped('os_template', __('By OS template'), __('OS template')),
            $grouped('billing_system', __('By billing system'), __('Billing system')),
            $this->months($locationId),
        ];
    }

    /**
     * Servers created (by the panel or adopted) and terminated per calendar
     * month in the panel's time zone, oldest month first.
     */
    private function months(?int $locationId): ReportTable
    {
        $timezone = (string) config('app.timezone');
        $first = CarbonImmutable::now($timezone)->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);

        $months = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $first->addMonthsNoOverflow($i)->format('Y-m');
            $months[$month] = ['month' => $month, 'created' => 0, 'adopted' => 0, 'terminated' => 0, 'net' => 0];
        }

        $servers = Server::query()
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->where(fn ($query) => $query->where('created_at', '>=', $first)->orWhere('terminated_at', '>=', $first))
            ->get(['id', 'origin', 'created_at', 'terminated_at']);

        foreach ($servers as $server) {
            $created = $server->created_at?->setTimezone($timezone)->format('Y-m');

            if ($created !== null && isset($months[$created])) {
                $months[$created][$server->origin === ServerOrigin::Provisioned ? 'created' : 'adopted']++;
                $months[$created]['net']++;
            }

            $terminated = $server->terminated_at?->setTimezone($timezone)->format('Y-m');

            if ($terminated !== null && isset($months[$terminated])) {
                $months[$terminated]['terminated']++;
                $months[$terminated]['net']--;
            }
        }

        $rows = array_values($months);

        return new ReportTable(
            'months',
            __('Per month'),
            __('Servers created by the panel, adopted from Proxmox and deleted in each month.'),
            [
                new ReportColumn('month', __('Month')),
                new ReportColumn('created', __('Created'), 'number'),
                new ReportColumn('adopted', __('Adopted'), 'number'),
                new ReportColumn('terminated', __('Deleted'), 'number'),
                new ReportColumn('net', __('Change'), 'number'),
            ],
            $rows,
            [
                'month' => __('Total'),
                'created' => Reports::sum($rows, 'created'),
                'adopted' => Reports::sum($rows, 'adopted'),
                'terminated' => Reports::sum($rows, 'terminated'),
                'net' => Reports::sum($rows, 'net'),
            ],
        );
    }
}
