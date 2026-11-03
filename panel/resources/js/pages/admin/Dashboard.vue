<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronRight,
    CircleCheck,
    CircleX,
    Gauge,
    GitCompareArrows,
    HardDrive,
    RefreshCwOff,
    ServerCrash,
    ServerOff,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import GuideBox from '@/components/admin/GuideBox.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { useDateTime } from '@/composables/useDateTime';
import { formatUsageValue } from '@/lib/format';
import { TASK_TYPE_LABELS } from '@/lib/taskTypes';
import { dashboard } from '@/routes/admin';
import { index as clusters } from '@/routes/admin/clusters';
import { index as drifts } from '@/routes/admin/drifts';
import { index as ipPools } from '@/routes/admin/ip-pools';
import { index as locations } from '@/routes/admin/locations';
import { index as osTemplates } from '@/routes/admin/os-templates';
import { index as packages } from '@/routes/admin/packages';
import { index as servers, show as showServer } from '@/routes/admin/servers';
import type { TaskType, UsageViolation } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: dashboard() }] },
});

const props = defineProps<{
    stats: {
        locations: number;
        clusters: number;
        clusters_failing: number;
        nodes: number;
        nodes_online: number;
        ip_addresses: number;
        ip_addresses_reserved: number;
        os_templates: number;
        packages: number;
        drifts: number;
        /** Storages and disks in warning or critical health (docs/adr/0047). */
        storage_problems: number;
    };
    /** Servers over their traffic quota or a usage limit right now (docs/adr/0039). */
    usageViolations: (UsageViolation & {
        server: { uuid: string; hostname: string };
    })[];
    usageViolationCount: number;
    /** Servers that still occupy capacity, by what they are doing. */
    servers: {
        total: number;
        active: number;
        suspended: number;
        busy: number;
        error: number;
    };
    /** Tasks that failed in the last 24 hours, newest first (at most 5). */
    failedTasks: {
        uuid: string;
        type: TaskType;
        error: string | null;
        finished_at: string | null;
        server: { uuid: string; hostname: string };
    }[];
    failedTaskCount: number;
}>();

const { formatDateTime } = useDateTime();

/** One problem the panel knows about; only those with a count are shown. */
type Attention = {
    key: string;
    label: string;
    count: number;
    severity: 'danger' | 'warning';
    icon: Component;
    href?: RouteDefinition<'get'>;
};

const attention = computed<Attention[]>(() =>
    (
        [
            {
                key: 'failed-tasks',
                label: 'Failed tasks in the last 24 hours',
                count: props.failedTaskCount,
                severity: 'danger',
                icon: CircleX,
            },
            {
                key: 'servers-error',
                label: 'Servers in error',
                count: props.servers.error,
                severity: 'danger',
                icon: ServerCrash,
                href: servers({ query: { status: 'error' } }),
            },
            {
                key: 'clusters-failing',
                label: 'Clusters that failed to sync',
                count: props.stats.clusters_failing,
                severity: 'warning',
                icon: RefreshCwOff,
                href: clusters(),
            },
            {
                key: 'nodes-offline',
                label: 'Nodes not online',
                count: props.stats.nodes - props.stats.nodes_online,
                severity: 'warning',
                icon: ServerOff,
                href: clusters(),
            },
            {
                key: 'storage',
                label: 'Storages or disks in poor health',
                count: props.stats.storage_problems,
                severity: 'warning',
                icon: HardDrive,
                href: clusters(),
            },
            {
                key: 'drift',
                label: 'Differences between Proxmox and the panel',
                count: props.stats.drifts,
                severity: 'warning',
                icon: GitCompareArrows,
                href: drifts(),
            },
            {
                key: 'usage',
                label: 'Servers over their limits',
                count: props.usageViolationCount,
                severity: 'warning',
                icon: Gauge,
            },
        ] satisfies Attention[]
    ).filter((item) => item.count > 0),
);

const hasDanger = computed(() =>
    attention.value.some((item) => item.severity === 'danger'),
);

/** Counts linking to the server list, filtered where one status fits. */
const serverStats = computed(() => [
    { label: 'All servers', value: props.servers.total, href: servers() },
    {
        label: 'Active',
        value: props.servers.active,
        href: servers({ query: { status: 'active' } }),
    },
    {
        label: 'Suspended',
        value: props.servers.suspended,
        href: servers({ query: { status: 'suspended' } }),
    },
    { label: 'In progress', value: props.servers.busy, href: servers() },
    {
        label: 'Error',
        value: props.servers.error,
        href: servers({ query: { status: 'error' } }),
        danger: props.servers.error > 0,
    },
]);

const inventory = computed(() => [
    {
        label: 'Locations',
        value: String(props.stats.locations),
        href: locations(),
    },
    {
        label: 'Clusters',
        value: String(props.stats.clusters),
        href: clusters(),
    },
    {
        label: 'Nodes online',
        value: `${props.stats.nodes_online} / ${props.stats.nodes}`,
        href: clusters(),
    },
    {
        label: 'IP addresses',
        value: String(props.stats.ip_addresses),
        detail: props.stats.ip_addresses_reserved
            ? {
                  key: ':count reserved',
                  count: String(props.stats.ip_addresses_reserved),
              }
            : undefined,
        href: ipPools(),
    },
    {
        label: 'Active OS templates',
        value: String(props.stats.os_templates),
        href: osTemplates(),
    },
    {
        label: 'Active packages',
        value: String(props.stats.packages),
        href: packages(),
    },
]);

const severityText = {
    danger: 'text-status-danger-text',
    warning: 'text-status-warning-text',
} as const;
</script>

<template>
    <Head title="Admin" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Admin dashboard"
            description="Overview of the infrastructure connected to the panel."
        />

        <GuideBox
            id="dashboard"
            title="New to Proxmotion?"
            topic="getting-started"
        >
            <p>
                {{
                    $t(
                        'Set the panel up in this order: location, Proxmox cluster, IP pool, OS templates, packages, billing system. Help shows which steps are done and explains each page.',
                    )
                }}
            </p>
        </GuideBox>

        <nav
            class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border bg-border sm:grid-cols-5"
            :aria-label="$t('Servers')"
            data-test="server-stats"
        >
            <Link
                v-for="(stat, index) in serverStats"
                :key="stat.label"
                :href="stat.href"
                class="flex flex-col gap-1 bg-background px-4 py-3 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                :class="{ 'col-span-2 sm:col-span-1': index === 0 }"
            >
                <span class="text-sm text-muted-foreground">
                    {{ $t(stat.label) }}
                </span>
                <span
                    class="text-2xl font-semibold tabular-nums"
                    :class="{ 'text-status-danger-text': stat.danger }"
                >
                    {{ stat.value }}
                </span>
            </Link>
        </nav>

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <section
                class="flex flex-col overflow-hidden rounded-xl border lg:col-span-2"
                :class="{
                    'border-status-warning-border':
                        attention.length > 0 && !hasDanger,
                    'border-destructive/40': hasDanger,
                }"
                data-test="attention"
            >
                <header class="px-4 pt-4 pb-3">
                    <Heading
                        variant="small"
                        title="Needs attention"
                        description="Problems the panel found in Proxmox, in tasks and in usage."
                    />
                </header>

                <div
                    v-if="attention.length === 0"
                    class="flex items-start gap-3 border-t px-4 py-4 text-sm"
                    data-test="attention-clear"
                >
                    <CircleCheck
                        class="mt-0.5 size-4 shrink-0 text-status-success-foreground"
                        aria-hidden="true"
                    />
                    <div>
                        <p class="font-medium">
                            {{ $t('Nothing needs attention') }}
                        </p>
                        <p class="text-muted-foreground">
                            {{
                                $t(
                                    'Clusters sync, nodes are online, storage is healthy, and no task failed in the last 24 hours.',
                                )
                            }}
                        </p>
                    </div>
                </div>

                <ul v-else class="flex flex-col divide-y border-t">
                    <li
                        v-for="item in attention"
                        :key="item.key"
                        :data-test="`attention-${item.key}`"
                    >
                        <component
                            :is="item.href ? Link : 'div'"
                            :href="item.href"
                            class="flex items-center gap-3 px-4 py-3 text-sm outline-none"
                            :class="
                                item.href
                                    ? 'transition-colors hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset'
                                    : ''
                            "
                        >
                            <component
                                :is="item.icon"
                                class="size-4 shrink-0"
                                :class="severityText[item.severity]"
                                aria-hidden="true"
                            />
                            <span class="flex-1 font-medium">
                                {{ $t(item.label) }}
                            </span>
                            <span class="font-semibold tabular-nums">
                                {{ item.count }}
                            </span>
                            <ChevronRight
                                v-if="item.href"
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </component>

                        <ul
                            v-if="item.key === 'failed-tasks'"
                            class="flex flex-col gap-2 pr-4 pb-3 pl-11 text-sm"
                            data-test="failed-tasks"
                        >
                            <li
                                v-for="task in failedTasks"
                                :key="task.uuid"
                                class="flex flex-col gap-0.5"
                            >
                                <div
                                    class="flex flex-wrap items-baseline gap-x-2"
                                >
                                    <Link
                                        :href="showServer(task.server.uuid)"
                                        class="font-medium underline-offset-4 hover:underline"
                                        >{{ task.server.hostname }}</Link
                                    >
                                    <span class="text-muted-foreground">
                                        {{ $t(TASK_TYPE_LABELS[task.type]) }}
                                        <template v-if="task.finished_at">
                                            ·
                                            {{
                                                formatDateTime(task.finished_at)
                                            }}
                                        </template>
                                    </span>
                                </div>
                                <p
                                    v-if="task.error"
                                    class="line-clamp-2 text-xs text-muted-foreground"
                                >
                                    {{ task.error }}
                                </p>
                            </li>
                            <li
                                v-if="failedTaskCount > failedTasks.length"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    $t('And :count more.', {
                                        count: String(
                                            failedTaskCount -
                                                failedTasks.length,
                                        ),
                                    })
                                }}
                            </li>
                        </ul>

                        <ul
                            v-if="item.key === 'usage'"
                            class="flex flex-col gap-2 pr-4 pb-3 pl-11 text-sm"
                            data-test="usage-violations"
                        >
                            <li
                                v-for="violation in usageViolations"
                                :key="violation.id"
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <Link
                                    :href="showServer(violation.server.uuid)"
                                    class="font-medium underline-offset-4 hover:underline"
                                    >{{ violation.server.hostname }}</Link
                                >
                                <span class="text-muted-foreground">
                                    {{ $t(violation.label) }}:
                                    {{
                                        formatUsageValue(
                                            violation.unit,
                                            violation.value,
                                        )
                                    }}
                                    /
                                    {{
                                        formatUsageValue(
                                            violation.unit,
                                            violation.threshold,
                                        )
                                    }}
                                    ·
                                    {{ formatDateTime(violation.started_at) }}
                                </span>
                                <StatusBadge
                                    v-if="
                                        violation.acted_at &&
                                        violation.action === 'suspend'
                                    "
                                    tone="warning"
                                    label="Suspended"
                                />
                                <StatusBadge
                                    v-else-if="
                                        violation.acted_at &&
                                        violation.action === 'stop'
                                    "
                                    tone="warning"
                                    label="Stopped"
                                />
                                <StatusBadge
                                    v-else
                                    tone="neutral"
                                    label="Recorded only"
                                />
                            </li>
                            <li
                                v-if="
                                    usageViolationCount > usageViolations.length
                                "
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    $t('And :count more.', {
                                        count: String(
                                            usageViolationCount -
                                                usageViolations.length,
                                        ),
                                    })
                                }}
                            </li>
                            <li class="text-xs text-muted-foreground">
                                {{
                                    $t(
                                        'What happens to them is set on Panel settings.',
                                    )
                                }}
                            </li>
                        </ul>
                    </li>
                </ul>
            </section>

            <section
                class="flex flex-col overflow-hidden rounded-xl border"
                data-test="inventory"
            >
                <header class="px-4 pt-4 pb-3">
                    <Heading variant="small" title="Infrastructure" />
                </header>
                <ul class="flex flex-col divide-y border-t">
                    <li v-for="row in inventory" :key="row.label">
                        <Link
                            :href="row.href"
                            class="flex items-baseline gap-3 px-4 py-3 text-sm transition-colors outline-none hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                        >
                            <span class="flex-1">{{ $t(row.label) }}</span>
                            <span
                                v-if="row.detail"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    $t(row.detail.key, {
                                        count: row.detail.count,
                                    })
                                }}
                            </span>
                            <span class="font-medium tabular-nums">
                                {{ row.value }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
