<script setup lang="ts">
import {
    Head,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { Eye, Monitor } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import PowerControls from '@/components/servers/PowerControls.vue';
import ResetPasswordButton from '@/components/servers/ResetPasswordButton.vue';
import TaskList from '@/components/servers/TaskList.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMib, formatTraffic } from '@/lib/format';
import { dashboard } from '@/routes';
import {
    console as openConsole,
    password,
    power as powerRoute,
    resetPassword,
    show,
} from '@/routes/servers';
import type { PowerAction, ServerStatus, TaskProgress } from '@/types/admin';

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
        password_unrevealed: boolean;
    };
    tasks: TaskProgress[];
    /** Deferred: undefined until loaded, null when unknown. */
    power?: string | null;
    /** Deferred: whether the guest agent answers; null when unknown. */
    agent?: boolean | null;
    revealedPassword: string | null;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'My servers', href: dashboard() },
        { title: props.server.hostname, href: show(props.server.uuid) },
    ],
});

const page = usePage();

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

const reveal = () =>
    router.visit(password(props.server.uuid), { preserveScroll: true });

// Once a newer password is waiting, the one shown earlier no longer works.
const shownPassword = ref(props.revealedPassword);
watch(
    () => props.revealedPassword,
    (value) => (shownPassword.value = value),
);
watch(
    () => props.server.password_unrevealed,
    (unrevealed) => unrevealed && (shownPassword.value = null),
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
            <PowerControls
                v-if="server.status === 'active'"
                :power="power"
                :busy="busy"
                :action="powerAction"
            />
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
            <div class="flex flex-col gap-1 sm:col-span-2">
                <dt class="text-muted-foreground">{{ $t('Root password') }}</dt>
                <template v-if="shownPassword">
                    <dd class="font-mono text-xs break-all select-all">
                        {{ shownPassword }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        {{
                            $t(
                                'Copy it now: it will not be shown again. Reset it if you lose it.',
                            )
                        }}
                    </dd>
                </template>
                <dd v-else-if="server.password_unrevealed">
                    <Button variant="outline" size="sm" @click="reveal">
                        <Eye class="h-4 w-4" />
                        {{ $t('Show once') }}
                    </Button>
                </dd>
                <dd v-else class="text-xs text-muted-foreground">
                    {{ $t('Hidden. Reset it to set a new one.') }}
                </dd>
                <dd
                    v-if="server.status === 'active'"
                    class="flex flex-col gap-1"
                >
                    <ResetPasswordButton
                        :action="resetPassword(server.uuid)"
                        :disabled="busy || agent === false"
                        class="self-start"
                    />
                    <span
                        v-if="agent === false"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            $t(
                                'Not available: the server must be running with qemu-guest-agent installed.',
                            )
                        }}
                    </span>
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
