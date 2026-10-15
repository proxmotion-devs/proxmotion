<script setup lang="ts">
import {
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleDashed,
    CircleX,
    Eye,
    LoaderCircle,
    Pause,
    Play,
    RotateCcw,
} from '@lucide/vue';
import { watch } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMib, formatTraffic } from '@/lib/format';
import { show as showCluster } from '@/routes/admin/clusters';
import { show as showPool } from '@/routes/admin/ip-pools';
import {
    destroy,
    index,
    password,
    retry,
    show,
    suspend,
    unsuspend,
} from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type {
    NetworkModel,
    ServerStatus,
    Task,
    TaskStatus,
    TaskType,
} from '@/types/admin';

const props = defineProps<{
    server: {
        uuid: string;
        hostname: string;
        status: ServerStatus;
        origin: 'provisioned' | 'adopted';
        vmid: number;
        network_model: NetworkModel;
        cpu_cores: number;
        memory_mib: number;
        disk_mib: number;
        port_speed_mbps: number | null;
        traffic_bytes: number | null;
        has_password: boolean;
        busy: boolean;
        user: { uuid: string; name: string; email: string };
        cluster: { uuid: string; name: string };
        node: string;
        storage: string | null;
        location: string;
        package: string | null;
        os_template: string | null;
        addresses: {
            address: string;
            version: 4 | 6;
            pool: { uuid: string; name: string };
        }[];
        provisioned_at: string | null;
        created_at: string | null;
    };
    tasks: Task[];
    revealedPassword: string | null;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Servers', href: index() },
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

const post = (action: ReturnType<typeof suspend>) =>
    router.visit(action, { preserveScroll: true });

// English keys, translated in the template.
const taskTypeLabels: Record<TaskType, string> = {
    create: 'Create',
    suspend: 'Suspend',
    unsuspend: 'Unsuspend',
    terminate: 'Delete',
    adopt: 'Adopt',
};
const taskStatusTone: Record<
    TaskStatus,
    'success' | 'warning' | 'danger' | 'neutral'
> = {
    queued: 'neutral',
    running: 'warning',
    succeeded: 'success',
    failed: 'danger',
};
const taskStatusLabels: Record<TaskStatus, string> = {
    queued: 'Queued',
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
};
const stepLabels: Record<string, string> = {
    clone: 'Clone template',
    configure: 'Configure CPU, memory, network and cloud-init',
    resize: 'Grow disk',
    firewall: 'Enable anti-spoofing firewall',
    start: 'Start',
    stop: 'Stop',
    destroy: 'Delete VM and disks',
    release_ips: 'Release IP addresses',
    adopt: 'Adopt',
};
const plannedSteps: Partial<Record<TaskType, string[]>> = {
    create: ['clone', 'configure', 'resize', 'firewall', 'start'],
    terminate: ['stop', 'destroy', 'release_ips'],
};

const stepRows = (task: Task) => {
    const done = new Map(task.steps.map((step) => [step.key, step]));
    const keys = [
        ...(plannedSteps[task.type] ?? []),
        ...task.steps.map((step) => step.key),
    ];

    return [...new Set(keys)].map((key) => ({
        key,
        step: done.get(key) ?? null,
    }));
};

const modelLabel =
    NETWORK_MODELS.find((model) => model.value === props.server.network_model)
        ?.label ?? props.server.network_model;
</script>

<template>
    <Head :title="server.hostname" />

    <div class="flex flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="server.hostname"
                    :description="`VMID ${server.vmid} · ${server.node}`"
                />
                <div class="flex flex-wrap gap-2">
                    <ServerStatusBadge :status="server.status" />
                    <StatusBadge
                        v-if="server.origin === 'adopted'"
                        tone="neutral"
                        label="Adopted"
                    />
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="server.status === 'active'"
                    variant="outline"
                    @click="post(suspend(server.uuid))"
                >
                    <Pause class="h-4 w-4" />
                    {{ $t('Suspend') }}
                </Button>
                <Button
                    v-if="server.status === 'suspended'"
                    variant="outline"
                    @click="post(unsuspend(server.uuid))"
                >
                    <Play class="h-4 w-4" />
                    {{ $t('Unsuspend') }}
                </Button>
                <Button
                    v-if="server.status === 'error'"
                    variant="outline"
                    @click="post(retry(server.uuid))"
                >
                    <RotateCcw class="h-4 w-4" />
                    {{ $t('Retry') }}
                </Button>
                <ConfirmDelete
                    v-if="
                        ['active', 'suspended', 'error'].includes(server.status)
                    "
                    with-label
                    :action="destroy(server.uuid)"
                    :name="server.hostname"
                    description="The VM and its disks are deleted on Proxmox and its IP addresses are released. This cannot be undone."
                />
            </div>
        </div>

        <dl
            class="grid gap-4 rounded-xl border p-4 text-sm sm:grid-cols-2 lg:grid-cols-4"
        >
            <div>
                <dt class="text-muted-foreground">{{ $t('Customer') }}</dt>
                <dd class="font-medium">{{ server.user.name }}</dd>
                <dd class="text-xs text-muted-foreground">
                    {{ server.user.email }}
                </dd>
            </div>
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
                <dd class="text-xs">
                    <Link
                        :href="showCluster(server.cluster.uuid)"
                        class="hover:underline"
                        >{{ server.cluster.name }}</Link
                    >
                    · <span class="font-mono">{{ server.node }}</span>
                    <template v-if="server.storage">
                        · {{ server.storage }}</template
                    >
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground">
                    {{ $t('IP addresses') }} · {{ $t(modelLabel) }}
                </dt>
                <dd
                    v-for="address in server.addresses"
                    :key="address.address"
                    class="font-mono text-xs"
                >
                    {{ address.address }}
                    <Link
                        :href="showPool(address.pool.uuid)"
                        class="text-muted-foreground hover:underline"
                        >({{ address.pool.name }})</Link
                    >
                </dd>
                <dd v-if="server.addresses.length === 0">—</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Root password') }}</dt>
                <dd
                    v-if="revealedPassword"
                    class="font-mono text-xs break-all select-all"
                >
                    {{ revealedPassword }}
                </dd>
                <dd v-else-if="server.has_password">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="post(password(server.uuid))"
                    >
                        <Eye class="h-4 w-4" />
                        {{ $t('Show') }}
                    </Button>
                </dd>
                <dd v-else>—</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Created') }}</dt>
                <dd>
                    {{
                        formatDateTime(
                            server.provisioned_at ?? server.created_at,
                            page.props.locale,
                        )
                    }}
                </dd>
            </div>
        </dl>

        <section class="flex flex-col gap-4">
            <Heading variant="small" title="Tasks" />

            <article
                v-for="task in tasks"
                :key="task.uuid"
                class="flex flex-col gap-3 rounded-xl border p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{
                            $t(taskTypeLabels[task.type])
                        }}</span>
                        <StatusBadge
                            :tone="taskStatusTone[task.status]"
                            :label="taskStatusLabels[task.status]"
                        />
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ formatDateTime(task.created_at, page.props.locale) }}
                    </span>
                </div>

                <ol class="flex flex-col gap-1 text-sm">
                    <li
                        v-for="row in stepRows(task)"
                        :key="row.key"
                        class="flex items-center gap-2"
                    >
                        <CircleCheck
                            v-if="row.step?.status === 'done'"
                            class="h-4 w-4 text-emerald-600"
                        />
                        <LoaderCircle
                            v-else-if="task.status === 'running'"
                            class="h-4 w-4 animate-spin text-muted-foreground"
                        />
                        <CircleX
                            v-else-if="task.status === 'failed'"
                            class="h-4 w-4 text-muted-foreground/50"
                        />
                        <CircleDashed
                            v-else
                            class="h-4 w-4 text-muted-foreground/50"
                        />
                        <span
                            :class="{
                                'text-muted-foreground':
                                    row.step?.status !== 'done',
                            }"
                        >
                            {{ $t(stepLabels[row.key] ?? row.key) }}
                        </span>
                        <span
                            v-if="row.step?.message"
                            class="text-xs text-muted-foreground"
                            >({{ row.step.message }})</span
                        >
                    </li>
                </ol>

                <p
                    v-if="task.error"
                    class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm break-words text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
                >
                    {{ task.error }}
                </p>
            </article>

            <p v-if="tasks.length === 0" class="text-sm text-muted-foreground">
                {{ $t('No tasks yet.') }}
            </p>
        </section>
    </div>
</template>
