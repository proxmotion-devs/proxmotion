<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { Eye, Monitor, Pause, Play, RotateCcw } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import DriftList from '@/components/admin/DriftList.vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import AppEntitlementList from '@/components/servers/AppEntitlementList.vue';
import AddressesDialog from '@/components/servers/AddressesDialog.vue';
import AppList from '@/components/servers/AppList.vue';
import PowerControls from '@/components/servers/PowerControls.vue';
import HostnameDialog from '@/components/servers/HostnameDialog.vue';
import RdnsDialog from '@/components/servers/RdnsDialog.vue';
import ReapplyAddressesButton from '@/components/servers/ReapplyAddressesButton.vue';
import ReinstallDialog from '@/components/servers/ReinstallDialog.vue';
import RemoveAddressButton from '@/components/servers/RemoveAddressButton.vue';
import ResourcesDialog from '@/components/servers/ResourcesDialog.vue';
import ResetPasswordButton from '@/components/servers/ResetPasswordButton.vue';
import ServerChecks from '@/components/servers/ServerChecks.vue';
import TaskList from '@/components/servers/TaskList.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { formatMib, formatTraffic } from '@/lib/format';
import { show as showCluster } from '@/routes/admin/clusters';
import { show as showPool } from '@/routes/admin/ip-pools';
import {
    addresses as changeAddresses,
    appEntitlements as grantEntitlement,
    apps as installApp,
    console as openConsole,
    destroy,
    hostname as changeHostname,
    index,
    password,
    power as powerRoute,
    rdns,
    reapplyAddresses,
    reinstall,
    resetPassword,
    resources,
    retry,
    show,
    suspend,
    test as testServer,
    trialReset,
    unsuspend,
    verify,
} from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type {
    AddressPoolOption,
    AppEntitlement,
    AppInstallation,
    AppOption,
    NetworkModel,
    Option,
    OsPlatform,
    PowerAction,
    ServerVerification,
    Drift,
    ReinstallOptions,
    ResourceOptions,
    ServerAddress,
    RevealedAppOutputs,
    ServerStatus,
    Task,
    UnavailableApp,
} from '@/types/admin';

const props = withDefaults(
    defineProps<{
        server: {
            uuid: string;
            hostname: string;
            /** Its address when the hostname was generated: the title (docs/adr/0031). */
            vm_name: string | null;
            status: ServerStatus;
            can_retry: boolean;
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
            /** New CPU or memory wait for a restart. */
            reboot_required: boolean;
            /** Drafts of apps may be tried on it (docs/adr/0018 §7). */
            is_test: boolean;
            billing_system: string | null;
            external_id: string | null;
            external_client_id: string | null;
            cluster: { uuid: string; name: string };
            node: string;
            storage: string | null;
            location: string;
            package: string | null;
            os_template: string | null;
            platform: OsPlatform;
            addresses: ServerAddress[];
            provisioned_at: string | null;
            verification: ServerVerification | null;
            created_at: string | null;
        };
        tasks: Task[];
        revealedPassword: string | null;
        revealedAppOutputs: RevealedAppOutputs | null;
        reinstallOptions: ReinstallOptions;
        resourceOptions: ResourceOptions;
        /** Pools addresses may be added from (docs/adr/0028). */
        addressPools: AddressPoolOption[];
        drifts: Drift[];
        /** Deferred: undefined until loaded, null when unknown. */
        power?: string | null;
        /** Deferred: whether the guest agent answers; undefined until loaded, null when unknown. */
        agent?: boolean | null;
        apps: AppInstallation[];
        appOptions: AppOption[];
        /** Active apps that do not fit the server, with the first reason. */
        unavailableApps: UnavailableApp[];
        entitlements: AppEntitlement[];
        entitlementOptions: Option[];
    }>(),
    // Vue turns a missing Boolean prop into false, which showed the agent as
    // unreachable while the deferred prop was still loading.
    { agent: undefined },
);

// A server named from its location's template is titled by its address (docs/adr/0031).
const title = computed(() => props.server.vm_name ?? props.server.hostname);

setLayoutProps({
    breadcrumbs: [
        { title: 'Servers', href: index() },
        { title: title.value, href: show(props.server.uuid) },
    ],
});

const { formatDateTime } = useDateTime();

// Refresh while a task runs, so progress and the power state show up live.
const { start, stop } = usePoll(
    3000,
    { only: ['server', 'tasks', 'power', 'agent', 'apps'] },
    { autoStart: false },
);

const busy = computed(
    () =>
        props.server.busy ||
        props.tasks.some(
            (task) => task.status === 'queued' || task.status === 'running',
        ) ||
        props.apps.some(
            (app) =>
                ['queued', 'installing', 'uninstalling', 'upgrading'].includes(
                    app.status,
                ) ||
                app.runs.some(
                    (run) =>
                        run.status === 'queued' || run.status === 'running',
                ),
        ),
);

watch(busy, (value) => (value ? start() : stop()), { immediate: true });

const powerAction = (action: PowerAction) =>
    powerRoute({ server: props.server.uuid, action });

const post = (action: ReturnType<typeof suspend>) =>
    router.visit(action, { preserveScroll: true });

// Only the password changes, so the page does not ask Proxmox for the power
// state and the guest agent again.
const revealPassword = () =>
    router.visit(password(props.server.uuid), {
        preserveScroll: true,
        only: ['revealedPassword'],
    });

const modelLabel =
    NETWORK_MODELS.find((model) => model.value === props.server.network_model)
        ?.label ?? props.server.network_model;

// Overview, apps and tasks on their own tabs. The tab survives the redirect
// back after an action (e.g. installing an app) for this server.
type Tab = 'overview' | 'apps' | 'tasks';
const tabKey = `server-tab:${props.server.uuid}`;
const storedTab = (): Tab | null => {
    try {
        const value = sessionStorage.getItem(tabKey);

        return value === 'overview' || value === 'apps' || value === 'tasks'
            ? value
            : null;
    } catch {
        return null;
    }
};
const tab = ref<Tab>(
    props.revealedAppOutputs ? 'apps' : (storedTab() ?? 'overview'),
);

watch(tab, (value) => {
    try {
        sessionStorage.setItem(tabKey, value);
    } catch {
        // Private windows: the tab is simply not remembered.
    }
});

const tabs = computed(() => [
    { value: 'overview' as Tab, label: 'Overview', count: null },
    {
        value: 'apps' as Tab,
        label: 'Apps',
        count: props.apps.filter((app) => app.status !== 'removed').length,
    },
    { value: 'tasks' as Tab, label: 'Tasks', count: null },
]);

const runningTasks = computed(() =>
    props.tasks.filter(
        (task) => task.status === 'queued' || task.status === 'running',
    ),
);

const active = computed(() => props.server.status === 'active');

const toggleTestServer = () =>
    router.visit(testServer(props.server.uuid), {
        data: { is_test: !props.server.is_test },
        preserveScroll: true,
    });

// Reinstall the test server from its template after trials (docs/adr/0018 §7).
const resetAfterTrial = () => {
    if (
        window.confirm(
            trans(
                'Reinstall :hostname from :template? Its disks are wiped and every app on it is removed. IP addresses and SSH keys stay; the root password is new.',
                {
                    hostname: props.server.hostname,
                    template: props.server.os_template ?? '—',
                },
            ),
        )
    ) {
        router.post(
            trialReset(props.server.uuid),
            {},
            { preserveScroll: true },
        );
    }
};
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-6 p-4">
        <!-- Who the server is, its state and the everyday controls. -->
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="title"
                    :description="`${server.vm_name ? `${server.hostname} · ` : ''}VMID ${server.vmid} · ${server.node} · ${server.os_template ?? '—'}`"
                />
                <div class="flex flex-wrap gap-2">
                    <ServerStatusBadge :status="server.status" />
                    <StatusBadge
                        v-if="server.origin === 'adopted'"
                        tone="neutral"
                        label="Adopted"
                    />
                    <StatusBadge
                        v-if="server.is_test"
                        tone="warning"
                        label="Test server"
                    />
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <PowerControls
                    v-if="active"
                    :power="power"
                    :busy="busy"
                    :action="powerAction"
                />
                <Button v-if="active" size="sm" as-child>
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
                    v-if="server.can_retry"
                    size="sm"
                    @click="post(retry(server.uuid))"
                >
                    <RotateCcw class="h-4 w-4" />
                    {{ $t('Retry') }}
                </Button>
            </div>
        </div>

        <p
            v-if="server.reboot_required"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            data-test="reboot-required"
        >
            {{
                $t(
                    'Some changes take effect when the server is restarted (Start or Reboot here).',
                )
            }}
        </p>

        <p
            v-if="active && agent === false"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            data-test="agent-unreachable"
        >
            {{
                $t(
                    'Password reset, reinstall and apps need a running qemu-guest-agent in the server.',
                )
            }}
        </p>

        <DriftList v-if="drifts.length > 0" :drifts="drifts" />

        <div role="tablist" class="flex gap-1 border-b">
            <button
                v-for="item in tabs"
                :key="item.value"
                type="button"
                role="tab"
                :aria-selected="tab === item.value"
                :data-test="`tab-${item.value}`"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    tab === item.value
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="tab = item.value"
            >
                {{ $t(item.label) }}
                <span
                    v-if="item.count"
                    class="ml-1 rounded-full bg-muted px-1.5 text-xs"
                    >{{ item.count }}</span
                >
                <span
                    v-if="item.value === 'tasks' && runningTasks.length"
                    class="ml-1 inline-block h-2 w-2 rounded-full bg-amber-500"
                />
            </button>
        </div>

        <template v-if="tab === 'overview'">
            <TaskList v-if="runningTasks.length" :tasks="runningTasks" />

            <div class="grid gap-4 lg:grid-cols-3">
                <section class="flex flex-col gap-3 rounded-xl border p-4">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-medium">{{ $t('Resources') }}</h3>
                        <ResourcesDialog
                            v-if="active"
                            :action="resources(server.uuid)"
                            :options="resourceOptions"
                            :disabled="busy"
                        />
                    </div>
                    <dl class="grid gap-2 text-sm">
                        <div>
                            <dt class="text-muted-foreground">
                                {{ $t('Package') }}
                            </dt>
                            <dd class="font-medium">
                                {{ server.package ?? $t('Custom') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                {{ $t('CPU, memory and disk') }}
                            </dt>
                            <dd>
                                {{ server.cpu_cores }} vCPU ·
                                {{ formatMib(server.memory_mib) }} ·
                                {{ formatMib(server.disk_mib) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                {{ $t('Port speed and traffic') }}
                            </dt>
                            <dd>
                                {{
                                    server.port_speed_mbps
                                        ? `${server.port_speed_mbps} Mbps`
                                        : '∞ Mbps'
                                }}
                                · {{ formatTraffic(server.traffic_bytes) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="flex flex-col gap-3 rounded-xl border p-4">
                    <h3 class="font-medium">{{ $t('Access') }}</h3>
                    <dl class="grid gap-2 text-sm">
                        <div>
                            <dt class="text-muted-foreground">
                                {{ $t('Root password') }}
                            </dt>
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
                                    @click="revealPassword"
                                >
                                    <Eye class="h-4 w-4" />
                                    {{ $t('Show') }}
                                </Button>
                            </dd>
                            <dd v-else>—</dd>
                        </div>
                        <div v-if="active">
                            <dt class="text-muted-foreground">
                                {{ $t('Guest agent') }}
                            </dt>
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
                        </div>
                    </dl>
                    <div v-if="active" class="mt-auto flex flex-wrap gap-2">
                        <ResetPasswordButton
                            :action="resetPassword(server.uuid)"
                            :disabled="busy || agent === false"
                        />
                        <HostnameDialog
                            :action="changeHostname(server.uuid)"
                            :hostname="server.hostname"
                            :platform="server.platform"
                            :disabled="busy"
                        />
                    </div>
                </section>

                <section class="flex flex-col gap-3 rounded-xl border p-4">
                    <h3 class="font-medium">
                        {{ $t('Placement and billing') }}
                    </h3>
                    <dl class="grid gap-2 text-sm">
                        <div>
                            <dt class="text-muted-foreground">
                                {{ $t('Location') }}
                            </dt>
                            <dd class="font-medium">{{ server.location }}</dd>
                            <dd class="text-xs">
                                <Link
                                    :href="showCluster(server.cluster.uuid)"
                                    class="hover:underline"
                                    >{{ server.cluster.name }}</Link
                                >
                                ·
                                <span class="font-mono">{{ server.node }}</span>
                                <template v-if="server.storage">
                                    · {{ server.storage }}</template
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                {{ $t('Billing service ID') }}
                            </dt>
                            <dd class="font-mono font-medium">
                                {{ server.external_id ?? '—' }}
                            </dd>
                            <dd
                                v-if="server.billing_system"
                                class="text-xs text-muted-foreground"
                            >
                                {{ server.billing_system }}
                            </dd>
                            <dd
                                v-if="server.external_client_id"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    $t('Client ID: :id', {
                                        id: server.external_client_id,
                                    })
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                {{ $t('Created') }}
                            </dt>
                            <dd>
                                {{
                                    formatDateTime(
                                        server.provisioned_at ??
                                            server.created_at,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section class="flex flex-col gap-3 rounded-xl border p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-medium">
                        {{ $t('IP addresses') }}
                        <span class="text-sm font-normal text-muted-foreground"
                            >· {{ $t(modelLabel) }}</span
                        >
                    </h3>
                    <div v-if="active" class="flex flex-wrap gap-2">
                        <ReapplyAddressesButton
                            :action="reapplyAddresses(server.uuid)"
                            :disabled="busy || server.addresses.length === 0"
                        />
                        <AddressesDialog
                            :action="changeAddresses(server.uuid)"
                            :addresses="server.addresses"
                            :pools="addressPools"
                            :disabled="busy"
                        />
                    </div>
                </div>
                <ul class="flex flex-col gap-1">
                    <li
                        v-for="address in server.addresses"
                        :key="address.address"
                        class="flex flex-wrap items-center gap-x-2 font-mono text-xs"
                    >
                        {{ address.address }}
                        <StatusBadge
                            v-if="address.primary"
                            tone="neutral"
                            label="Primary"
                        />
                        <Link
                            :href="showPool(address.pool.uuid)"
                            class="text-muted-foreground hover:underline"
                            >({{ address.pool.name }})</Link
                        >
                        <span v-if="address.ptr" class="text-muted-foreground">
                            → {{ address.ptr }}</span
                        >
                        <span
                            v-if="address.ptr_pending"
                            class="text-amber-700 dark:text-amber-400"
                        >
                            ({{ $t('pending') }})</span
                        >
                        <RdnsDialog
                            v-if="active"
                            :action="rdns(server.uuid)"
                            :address="address.address"
                            :ptr="address.ptr"
                        />
                        <RemoveAddressButton
                            v-if="active && !address.primary"
                            :action="changeAddresses(server.uuid)"
                            :address="address.address"
                            :disabled="busy"
                        />
                    </li>
                    <li v-if="server.addresses.length === 0">—</li>
                </ul>
            </section>

            <ServerChecks
                v-if="active || server.verification"
                :verification="server.verification"
                :action="active ? verify(server.uuid) : undefined"
                :disabled="busy"
            />

            <!-- Operations that interrupt or remove the server. -->
            <section
                v-if="['active', 'suspended', 'error'].includes(server.status)"
                class="flex flex-col gap-3 rounded-xl border border-destructive/30 p-4"
                data-test="server-lifecycle"
            >
                <div>
                    <h3 class="font-medium">
                        {{ $t('Suspend, reinstall or delete') }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'Billing systems usually do this for you. Reinstalling and deleting cannot be undone.',
                            )
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="active"
                        variant="outline"
                        size="sm"
                        :disabled="busy"
                        @click="post(suspend(server.uuid))"
                    >
                        <Pause class="h-4 w-4" />
                        {{ $t('Suspend') }}
                    </Button>
                    <Button
                        v-if="server.status === 'suspended'"
                        variant="outline"
                        size="sm"
                        :disabled="busy"
                        @click="post(unsuspend(server.uuid))"
                    >
                        <Play class="h-4 w-4" />
                        {{ $t('Unsuspend') }}
                    </Button>
                    <ReinstallDialog
                        v-if="active"
                        :action="reinstall(server.uuid)"
                        :hostname="server.hostname"
                        :options="reinstallOptions"
                        :disabled="busy"
                    />
                    <ConfirmDelete
                        with-label
                        :action="destroy(server.uuid)"
                        :name="server.hostname"
                        description="The VM and its disks are deleted on Proxmox and its IP addresses are released. This cannot be undone."
                    />
                </div>
            </section>
        </template>

        <template v-else-if="tab === 'apps'">
            <AppList
                :apps="apps"
                :options="appOptions"
                :unavailable="unavailableApps"
                :action="installApp(server.uuid)"
                :disabled="!active || agent === false"
                :revealed="revealedAppOutputs"
            />

            <section
                v-if="entitlements.length || entitlementOptions.length"
                class="flex flex-col gap-4"
            >
                <Heading variant="small" title="Paid apps" />
                <AppEntitlementList
                    :entitlements="entitlements"
                    :options="entitlementOptions"
                    :action="grantEntitlement(server.uuid)"
                    :disabled="server.status === 'terminated'"
                />
            </section>

            <!-- Trial runs of app drafts (docs/adr/0018 §7); never on a billing system's server. -->
            <section
                v-if="!server.billing_system && server.status !== 'terminated'"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                data-test="test-server"
            >
                <div>
                    <h3 class="font-medium">{{ $t('Test server') }}</h3>
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'Drafts of apps can be tried on test servers from the app editor. Only use a server nobody depends on. Reset after trial reinstalls it from its template, removing every app.',
                            )
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="server.is_test && active"
                        variant="destructive"
                        size="sm"
                        :disabled="busy"
                        data-test="trial-reset"
                        @click="resetAfterTrial"
                    >
                        <RotateCcw class="h-4 w-4" />
                        {{ $t('Reset after trial') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="toggleTestServer"
                    >
                        {{
                            server.is_test
                                ? $t('Stop using as a test server')
                                : $t('Use as a test server')
                        }}
                    </Button>
                </div>
            </section>
        </template>

        <TaskList v-else :tasks="tasks" />
    </div>
</template>
