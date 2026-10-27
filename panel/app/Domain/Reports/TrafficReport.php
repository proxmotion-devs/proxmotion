<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Usage\TrafficPeriods;
use App\Enums\UsageAction;
use App\Models\Server;
use App\Models\TrafficPeriod;
use App\Models\UsageViolation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Traffic of one monthly period (docs/adr/0039) per server and summed per
 * location, package and billing system, and the usage violations that
 * started in it. Quotas are the servers' current ones.
 */
final class TrafficReport implements Report
{
    use FiltersByLocation;

    /** Periods offered, the current one first. */
    private const int PERIODS = 12;

    public function rules(): array
    {
        return [
            ...$this->locationRules(),
            'period' => ['nullable', 'string', Rule::in(array_column($this->periods(), 'value'))],
        ];
    }

    public function filters(array $input): array
    {
        return [
            'period' => $input['period'] ?? TrafficPeriods::start()->toDateString(),
            'location' => $input['location'] ?? null,
        ];
    }

    public function filterOptions(): array
    {
        return [
            'period' => ['label' => __('Period'), 'all' => null, 'options' => $this->periods()],
            'location' => $this->locationOptions(),
        ];
    }

    public function tables(array $filters): array
    {
        $start = CarbonImmutable::parse((string) $filters['period'], (string) config('app.timezone'));
        $locationId = $this->locationId($filters['location']);
        $inLocation = fn (Builder $query) => $query->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId));

        $rows = array_values(TrafficPeriod::query()
            ->whereDate('period_start', $start->toDateString())
            ->whereHas('server', $inLocation)
            ->with([
                'server:id,uuid,hostname,location_id,package_id,billing_system_id,external_id,traffic_bytes',
                'server.location:id,name',
                'server.package:id,name',
                'server.billingSystem:id,name',
            ])
            ->get()
            ->map(fn (TrafficPeriod $period) => [
                'server' => $period->server->hostname,
                'server_uuid' => $period->server->uuid,
                'location' => $period->server->location->name,
                'package' => $period->server->package?->name,
                'billing_system' => $period->server->billingSystem?->name,
                'external_id' => $period->server->external_id,
                'in_bytes' => $period->in_bytes,
                'out_bytes' => $period->out_bytes,
                'counted_bytes' => $period->counted(),
                'quota_bytes' => $period->server->traffic_bytes,
                'quota_percent' => Reports::percent($period->counted(), $period->server->traffic_bytes),
            ])
            ->sortByDesc('counted_bytes')
            ->values()
            ->all());

        $sums = ['in_bytes', 'out_bytes', 'counted_bytes'];
        $groupColumns = fn (string $key, string $label) => [
            new ReportColumn($key, $label),
            new ReportColumn('servers', __('Servers'), 'number'),
            new ReportColumn('in_bytes', __('Received'), 'traffic'),
            new ReportColumn('out_bytes', __('Sent'), 'traffic'),
            new ReportColumn('counted_bytes', __('Counted'), 'traffic'),
        ];

        return [
            new ReportTable(
                'servers',
                __('Servers'),
                __('Traffic of each server in the period, largest first. Counted is what counts against the quota (Panel settings).'),
                [
                    new ReportColumn('server', __('Server'), 'server'),
                    new ReportColumn('location', __('Location')),
                    new ReportColumn('package', __('Package')),
                    new ReportColumn('billing_system', __('Billing system')),
                    new ReportColumn('external_id', __('Service ID')),
                    new ReportColumn('in_bytes', __('Received'), 'traffic'),
                    new ReportColumn('out_bytes', __('Sent'), 'traffic'),
                    new ReportColumn('counted_bytes', __('Counted'), 'traffic'),
                    new ReportColumn('quota_bytes', __('Quota'), 'traffic'),
                    new ReportColumn('quota_percent', __('Of quota'), 'percent'),
                ],
                $rows,
                $rows === [] ? null : [
                    'server' => __('Total'),
                    'in_bytes' => Reports::sum($rows, 'in_bytes'),
                    'out_bytes' => Reports::sum($rows, 'out_bytes'),
                    'counted_bytes' => Reports::sum($rows, 'counted_bytes'),
                ],
            ),
            new ReportTable(
                'locations',
                __('By location'),
                null,
                $groupColumns('location', __('Location')),
                Reports::group($rows, 'location', fn (array $row) => (string) $row['location'], 'servers', $sums),
            ),
            new ReportTable(
                'packages',
                __('By package'),
                null,
                $groupColumns('package', __('Package')),
                Reports::group($rows, 'package', fn (array $row): string => is_string($row['package']) ? $row['package'] : __('No package'), 'servers', $sums),
            ),
            new ReportTable(
                'billing_systems',
                __('By billing system'),
                null,
                $groupColumns('billing_system', __('Billing system')),
                Reports::group($rows, 'billing_system', fn (array $row): string => is_string($row['billing_system']) ? $row['billing_system'] : __('None'), 'servers', $sums),
            ),
            $this->violations($start, $inLocation),
        ];
    }

    /**
     * @param  Closure(Builder<Server>): mixed  $inLocation
     */
    private function violations(CarbonImmutable $start, Closure $inLocation): ReportTable
    {
        $rows = array_values(UsageViolation::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', TrafficPeriods::end($start))
            ->whereHas('server', $inLocation)
            ->with('server:id,uuid,hostname')
            ->latest('id')
            ->get()
            ->map(fn (UsageViolation $violation) => [
                'server' => $violation->server->hostname,
                'server_uuid' => $violation->server->uuid,
                'kind' => __($violation->kind->label()),
                'value' => $violation->value,
                'threshold' => $violation->threshold,
                'unit' => $violation->kind->unit(),
                'action' => match ($violation->action) {
                    UsageAction::None => __('Recorded only'),
                    UsageAction::Stop => __('Stopped'),
                    UsageAction::Suspend => __('Suspended'),
                },
                'started_at' => $violation->created_at?->toIso8601String(),
                'resolved_at' => $violation->resolved_at?->toIso8601String(),
            ])
            ->all());

        return new ReportTable(
            'violations',
            __('Usage violations'),
            __('Servers that went over their traffic quota or a usage limit in the period. Resolved violations are deleted after 90 days.'),
            [
                new ReportColumn('server', __('Server'), 'server'),
                new ReportColumn('kind', __('Limit')),
                new ReportColumn('value', __('Value'), 'usage'),
                new ReportColumn('threshold', __('Threshold'), 'usage'),
                new ReportColumn('unit', __('Unit')),
                new ReportColumn('action', __('Action')),
                new ReportColumn('started_at', __('Started'), 'datetime'),
                new ReportColumn('resolved_at', __('Resolved'), 'datetime'),
            ],
            $rows,
        );
    }

    /**
     * The current period and the ones before it.
     *
     * @return list<array{value: string, label: string}>
     */
    private function periods(): array
    {
        $current = TrafficPeriods::start();
        $periods = [];

        for ($i = 0; $i < self::PERIODS; $i++) {
            $start = $current->subMonthsNoOverflow($i);
            $periods[] = [
                'value' => $start->toDateString(),
                'label' => $start->toDateString().' – '.TrafficPeriods::end($start)->subDay()->toDateString(),
            ];
        }

        return $periods;
    }
}
