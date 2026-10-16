<script setup lang="ts">
import { Head, setLayoutProps, usePage, usePoll } from '@inertiajs/vue3';
import { Monitor } from '@lucide/vue';
import { watch } from 'vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import TaskList from '@/components/servers/TaskList.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMib, formatTraffic } from '@/lib/format';
import { dashboard } from '@/routes';
import { console as openConsole, show } from '@/routes/servers';
import type { ServerStatus, TaskProgress } from '@/types/admin';

const props = defineProps<{
    server: {
        uuid: string;
        hostname: string;
        status: ServerStatus;
        busy: boolean;
        cpu_cores: number;
        memory_mib: number;
        disk_mib: number;
        port_speed_mbps: number | null;
        traffic_bytes: number | null;
        location: string;
        package: string | null;
        os_template: string | null;
        addresses: { address: string; version: 4 | 6 }[];
        created_at: string | null;
    };
    tasks: TaskProgress[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'My servers', href: dashboard() },
        { title: props.server.hostname, href: show(props.server.uuid) },
    ],
});

const page = usePage();

// Refresh while a task runs, so progress shows up live.
const { start, stop } = usePoll(
    3000,
    { only: ['server', 'tasks'] },
    { autoStart: false },
);

watch(
    () =>
        props.server.busy ||
        props.tasks.some(
            (task) => task.status === 'queued' || task.status === 'running',
        ),
    (busy) => (busy ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head :title="server.hostname" />

    <div class="flex flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-2">
                <Heading :title="server.hostname" />
                <ServerStatusBadge :status="server.status" />
            </div>
            <Button v-if="server.status === 'active'" as-child>
                <a
                    :href="openConsole(server.uuid).url"
                    target="_blank"
                    rel="noopener"
                >
                    <Monitor class="h-4 w-4" />
                    {{ $t('Console') }}
                </a>
            </Button>
        </div>

        <p
            v-if="server.status === 'suspended'"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
        >
            {{
                $t(
                    'This server is suspended. Please contact support or check your billing account.',
                )
            }}
        </p>
        <p
            v-else-if="server.status === 'error'"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
        >
            {{
                $t(
                    'Something went wrong with this server. Please contact support.',
                )
            }}
        </p>

        <dl
            class="grid gap-4 rounded-xl border p-4 text-sm sm:grid-cols-2 lg:grid-cols-4"
        >
            <div>
                <dt class="text-muted-foreground">{{ $t('Package') }}</dt>
                <dd class="font-medium">
                    {{ server.package ?? $t('Custom') }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    {{ server.cpu_cores }} vCPU ·
                    {{ formatMib(server.memory_mib) }} ·
                    {{ formatMib(server.disk_mib) }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    {{
                        server.port_speed_mbps
                            ? `${server.port_speed_mbps} Mbps`
                            : '∞ Mbps'
                    }}
                    ·
                    {{ formatTraffic(server.traffic_bytes) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    {{ $t('Operating system') }}
                </dt>
                <dd class="font-medium">{{ server.os_template ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Location') }}</dt>
                <dd class="font-medium">{{ server.location }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Created') }}</dt>
                <dd>
                    {{ formatDateTime(server.created_at, page.props.locale) }}
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground">{{ $t('IP addresses') }}</dt>
                <dd
                    v-for="address in server.addresses"
                    :key="address.address"
                    class="font-mono text-xs select-all"
                >
                    {{ address.address }}
                    <span class="text-muted-foreground"
                        >IPv{{ address.version }}</span
                    >
                </dd>
                <dd v-if="server.addresses.length === 0">—</dd>
            </div>
        </dl>

        <section class="flex flex-col gap-4">
            <Heading variant="small" title="Recent activity" />
            <TaskList :tasks="tasks" />
        </section>
    </div>
</template>
