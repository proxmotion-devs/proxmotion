<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { formatMib, formatTraffic, formatUsageValue } from '@/lib/format';
import { TASK_TYPE_LABELS } from '@/lib/taskTypes';
import { show as showServer } from '@/routes/admin/servers';
import type {
    ReportCell,
    ReportColumnType,
    ReportTable,
    ServerStatus,
    TaskType,
    UsageViolation,
} from '@/types/admin';

/**
 * One table of a report (docs/adr/0046). Cells are raw values, formatted
 * here by their column's type; at most a page of rows is shown, the CSV
 * download has all of them.
 */
defineProps<{ table: ReportTable; csvHref: string }>();

const page = usePage();
const { formatDateTime } = useDateTime();

const NUMERIC: ReportColumnType[] = [
    'number',
    'mib',
    'traffic',
    'percent',
    'duration',
    'usage',
];

// "45 s", "12 min 5 s", "2 h 10 min", from whole seconds.
const formatDuration = (seconds: number): string => {
    const total = Math.max(0, Math.round(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;

    if (hours > 0) {
        return trans(':hours h :minutes min', {
            hours: String(hours),
            minutes: String(minutes),
        });
    }

    if (minutes === 0) {
        return trans(':seconds s', { seconds: String(rest) });
    }

    return rest === 0
        ? trans(':minutes min', { minutes: String(minutes) })
        : trans(':minutes min :seconds s', {
              minutes: String(minutes),
              seconds: String(rest),
          });
};

const formatNumber = (value: number): string =>
    new Intl.NumberFormat(page.props.locale).format(value);

const format = (
    type: ReportColumnType,
    value: ReportCell | undefined,
    row: Record<string, ReportCell>,
): string => {
    if (value === undefined) {
        return '';
    }

    // An unknown quota is no quota.
    if (value === null) {
        return type === 'traffic' ? formatTraffic(null) : '—';
    }

    if (typeof value === 'boolean') {
        return trans(value ? 'Yes' : 'No');
    }

    if (typeof value === 'string') {
        switch (type) {
            case 'datetime':
                return formatDateTime(value);
            case 'task_type':
                return trans(TASK_TYPE_LABELS[value as TaskType] ?? value);
            default:
                return value;
        }
    }

    switch (type) {
        case 'mib':
            return formatMib(value);
        case 'traffic':
            return formatTraffic(value);
        case 'percent':
            return `${formatNumber(value)}%`;
        case 'duration':
            return formatDuration(value);
        case 'usage':
            return formatUsageValue(row.unit as UsageViolation['unit'], value);
        default:
            return formatNumber(value);
    }
};
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h3 class="font-medium">{{ table.title }}</h3>
                <p
                    v-if="table.description"
                    class="text-sm text-muted-foreground"
                >
                    {{ table.description }}
                </p>
            </div>
            <Button
                v-if="table.row_count > 0"
                variant="outline"
                size="sm"
                as-child
            >
                <a :href="csvHref" download>
                    <Download class="size-4" />
                    {{ $t('CSV') }}
                </a>
            </Button>
        </div>

        <div
            v-if="table.row_count > 0"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th
                            v-for="column in table.columns"
                            :key="column.key"
                            class="px-4 py-3 font-medium whitespace-nowrap"
                            :class="{
                                'text-right': NUMERIC.includes(column.type),
                            }"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in table.rows"
                        :key="index"
                        class="border-t"
                    >
                        <td
                            v-for="column in table.columns"
                            :key="column.key"
                            class="px-4 py-2"
                            :class="{
                                'text-right whitespace-nowrap tabular-nums':
                                    NUMERIC.includes(column.type),
                                'max-w-md break-words': column.type === 'text',
                            }"
                        >
                            <Link
                                v-if="
                                    column.type === 'server' &&
                                    typeof row.server_uuid === 'string'
                                "
                                :href="showServer(row.server_uuid)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ row[column.key] }}
                            </Link>
                            <ServerStatusBadge
                                v-else-if="
                                    column.type === 'server_status' &&
                                    typeof row[column.key] === 'string'
                                "
                                :status="row[column.key] as ServerStatus"
                            />
                            <template v-else>
                                {{ format(column.type, row[column.key], row) }}
                            </template>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="table.totals" class="border-t bg-muted/30">
                    <tr class="font-medium">
                        <td
                            v-for="(column, index) in table.columns"
                            :key="column.key"
                            class="px-4 py-2"
                            :class="{
                                'text-right whitespace-nowrap tabular-nums':
                                    NUMERIC.includes(column.type),
                            }"
                        >
                            {{
                                index === 0
                                    ? table.totals[column.key]
                                    : format(
                                          column.type,
                                          table.totals[column.key],
                                          table.totals,
                                      )
                            }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p
            v-else
            class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            {{ $t('Nothing to report.') }}
        </p>

        <p
            v-if="table.row_count > table.rows.length"
            class="text-xs text-muted-foreground"
        >
            {{
                $t(
                    'Showing the first :shown of :total rows; the CSV has all of them.',
                    {
                        shown: String(table.rows.length),
                        total: String(table.row_count),
                    },
                )
            }}
        </p>
    </section>
</template>
