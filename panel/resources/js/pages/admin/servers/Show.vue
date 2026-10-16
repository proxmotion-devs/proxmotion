<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { Eye, Monitor, Pause, Play, RotateCcw } from '@lucide/vue';
import { computed, watch } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import PowerControls from '@/components/servers/PowerControls.vue';
import ResetPasswordButton from '@/components/servers/ResetPasswordButton.vue';
import TaskList from '@/components/servers/TaskList.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { formatMib, formatTraffic } from '@/lib/format';
import { show as showCluster } from '@/routes/admin/clusters';
import { show as showPool } from '@/routes/admin/ip-pools';
import {
    console as openConsole,
    destroy,
    index,
    password,
    power as powerRoute,
    resetPassword,
    retry,
    show,
    suspend,
    unsuspend,
} from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type {
    NetworkModel,
    PowerAction,
    ServerStatus,
    Task,
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
    /** Deferred: undefined until loaded, null when unknown. */
    power?: string | null;
    /** Deferred: whether the guest agent answers; null when unknown. */
    agent?: boolean | null;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Servers', href: index() },
        { title: props.server.hostname, href: show(props.server.uuid) },
    ],
});

const { formatDateTime } = useDateTime();

// Refresh while a task runs, so progress and the power state show up live.
const { start, stop } = usePoll(
    3000,
    { only: ['server', 'tasks', 'power', 'agent'] },
    { autoStart: false },
);

const busy = computed(
    () =>
        props.server.busy ||
        props.tasks.some(
            (task) => task.status === 'queued' || task.status === 'running',
        ),
);

watch(busy, (value) => (value ? start() : stop()), { immediate: true });

const powerAction = (action: PowerAction) =>
    powerRoute({ server: props.server.uuid, action });

const post = (action: ReturnType<typeof suspend>) =>
    router.visit(action, { preserveScroll: true });

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

        <PowerControls
            v-if="server.status === 'active'"
            :power="power"
            :busy="busy"
            :action="powerAction"
        />

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
            <div v-if="server.status === 'active'">
                <dt class="text-muted-foreground">{{ $t('Guest agent') }}</dt>
                <dd class="font-medium">
                    <template v-if="agent === undefined">{{
                        $t('Checking…')
                    }}</template>
                    <template v-else-if="agent === true">{{
                        $t('Reachable')
                    }}</template>
                    <template v-else-if="agent === false">{{
                        $t('Not reachable')
                    }}</template>
                    <template v-else>{{ $t('Unknown') }}</template>
                </dd>
                <dd
                    v-if="agent === false"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        $t(
                            'Password reset, reinstall and apps need a running qemu-guest-agent in the server.',
                        )
                    }}
                </dd>
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
                <dd v-if="server.status === 'active'" class="mt-1">
                    <ResetPasswordButton
                        :action="resetPassword(server.uuid)"
                        :disabled="busy || agent === false"
                    />
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Created') }}</dt>
                <dd>
                    {{
                        formatDateTime(
                            server.provisioned_at ?? server.created_at,
                        )
                    }}
                </dd>
            </div>
        </dl>

        <section class="flex flex-col gap-4">
            <Heading variant="small" title="Tasks" />
            <TaskList :tasks="tasks" />
        </section>
    </div>
</template>
