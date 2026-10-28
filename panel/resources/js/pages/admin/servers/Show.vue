<script setup lang="ts">
import {
    Head,
    Link,
    router,
    setLayoutProps,
    useHttp,
    usePoll,
} from '@inertiajs/vue3';
import { Eye, Monitor, RotateCcw } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
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
import MoveDialog from '@/components/servers/MoveDialog.vue';
import MoveHistory from '@/components/servers/MoveHistory.vue';
import RdnsDialog from '@/components/servers/RdnsDialog.vue';
import RebuildDialog from '@/components/servers/RebuildDialog.vue';
import AntiSpoofingButton from '@/components/servers/AntiSpoofingButton.vue';
import FirewallSection from '@/components/servers/FirewallSection.vue';
import SshKeysSection from '@/components/servers/SshKeysSection.vue';
import ReapplyAddressesButton from '@/components/servers/ReapplyAddressesButton.vue';
import ReinstallDialog from '@/components/servers/ReinstallDialog.vue';
import ReinstallLimitSection from '@/components/servers/ReinstallLimitSection.vue';
import RemoveAddressButton from '@/components/servers/RemoveAddressButton.vue';
import ResourcesDialog from '@/components/servers/ResourcesDialog.vue';
import ResetPasswordButton from '@/components/servers/ResetPasswordButton.vue';
import ServerChecks from '@/components/servers/ServerChecks.vue';
import ServerScripts from '@/components/servers/ServerScripts.vue';
import ServerStatsCards from '@/components/servers/ServerStatsCards.vue';
import SuspendDialog from '@/components/servers/SuspendDialog.vue';
import TaskList from '@/components/servers/TaskList.vue';
import UnsuspendDialog from '@/components/servers/UnsuspendDialog.vue';
import UsageSection from '@/components/servers/UsageSection.vue';
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
    enableAntiSpoofing,
    hostname as changeHostname,
    index,
    move as moveServer,
    password,
    power as powerRoute,
    rdns,
    reapplyAddresses,
    rebuild,
    reinstall,
    reinstallLimit as updateReinstallLimit,
    resetPassword,
    resources,
    retry,
    show,
    suspend,
    test as testServer,
    trialReset,
    unsuspend,
    usageExempt,
    verify,
} from '@/routes/admin/servers';
import { finish as finishMove } from '@/routes/admin/servers/moves';
import { reset as resetReinstalls } from '@/routes/admin/servers/reinstall-limit';
import { NETWORK_MODELS } from '@/types/admin';
import type {
    AddressPoolOption,
    AppEntitlement,
    AppInstallation,
    AppOption,
    MoveOptions,
    NetworkModel,
    Option,
    OsPlatform,
    PowerAction,
    ServerVerification,
    Drift,
    GuestScriptRun,
    ReinstallLimitState,
    ReinstallOptions,
    ResourceOptions,
    ServerFirewall,
    ServerSshKeys,
    ServerAddress,
    ServerUsage,
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
            can_rebuild: boolean;
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
            suspension_reason: string | null;
            /** Set when the panel suspended it itself (docs/adr/0039). */
            suspension_cause: 'traffic' | 'usage' | null;
            usage_exempt: boolean;
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
            /** When the VM firewall was last compared with what anti-spoofing needs (docs/adr/0033). */
            firewall_checked_at: string | null;
            created_at: string | null;
        };
        tasks: Task[];
        revealedAppOutputs: RevealedAppOutputs | null;
        reinstallOptions: ReinstallOptions;
        /** docs/adr/0045 */
        reinstallLimit: ReinstallLimitState;
        resourceOptions: ResourceOptions;
        /** docs/adr/0037 */
        moveOptions: MoveOptions;
        /** docs/adr/0035 */
        firewall: ServerFirewall;
        /** docs/adr/0049 */
        sshKeys: ServerSshKeys;
        /** Pools addresses may be added from (docs/adr/0028). */
        addressPools: AddressPoolOption[];
        drifts: Drift[];
        /** docs/adr/0039 */
        usage: ServerUsage;
        /** Deferred: undefined until loaded, null when unknown or the VM is not where the panel recorded it. */
        power?: string | null;
        /** Deferred: whether the VM is where the panel recorded it; node: where Proxmox has it when elsewhere. */
        vm?: {
            state: 'present' | 'missing' | 'elsewhere' | 'moving';
            node: string | null;
        };
        /** Deferred: whether the guest agent answers; undefined until loaded, null when unknown or not asked (VM not running). */
        agent?: boolean | null;
        apps: AppInstallation[];
        appOptions: AppOption[];
        /** Active apps that do not fit the server, with the first reason. */
        unavailableApps: UnavailableApp[];
        entitlements: AppEntitlement[];
        entitlementOptions: Option[];
        /** The template's manual scripts and the latest custom script runs here (docs/adr/0043). */
        scripts: { uuid: string; name: string }[];
        scriptRuns: GuestScriptRun[];
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
    // drifts too: a finished task can close one (Enable anti-spoofing, docs/adr/0033).
    // moveOptions: the move's progress and history (docs/adr/0037).
    {
        only: [
            'server',
            'tasks',
            'power',
            'vm',
            'agent',
            'apps',
            'drifts',
            'moveOptions',
            'scriptRuns',
        ],
    },
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

// Otherwise follow Proxmox and the server more slowly, so a VM stopped,
// started, deleted or moved there, a task a billing system started and a drift
// the sync recorded show up without a reload. The guest agent (one more request
// to Proxmox) is asked only every fourth time, or once the power state changed,
// and nothing is asked while the page is hidden.
const WATCH_INTERVAL = 15000;
const AGENT_EVERY = 4;
let watchTimer: ReturnType<typeof setInterval> | null = null;
let watchTicks = 0;
/** The power state when the agent was last asked: a VM just started gets its agent checked at once. */
let agentAskedAt: string | null | undefined = undefined;

const watchTick = () => {
    if (document.visibilityState === 'hidden') {
        return;
    }

    watchTicks++;
    const askAgent =
        watchTicks % AGENT_EVERY === 0 || props.power !== agentAskedAt;

    if (askAgent) {
        agentAskedAt = props.power;
    }

    router.reload({
        only: [
            'server',
            'tasks',
            'apps',
            'power',
            'vm',
            'drifts',
            ...(askAgent ? ['agent'] : []),
        ],
        async: true,
    });
};

const stopWatching = () => {
    if (watchTimer !== null) {
        clearInterval(watchTimer);
        watchTimer = null;
    }
};

watch(
    () => !busy.value && props.server.status !== 'terminated',
    (value) => {
        stopWatching();

        if (value) {
            watchTicks = 0;
            watchTimer = setInterval(watchTick, WATCH_INTERVAL);
        }
    },
    { immediate: true },
);

onBeforeUnmount(stopWatching);

const powerAction = (action: PowerAction) =>
    powerRoute({ server: props.server.uuid, action });

const post = (action: ReturnType<typeof suspend>) =>
    router.visit(action, { preserveScroll: true });

// Fetched as JSON and kept only here: no page visit, and the password never
// becomes a page prop that Inertia keeps in the browser's history state.
const passwordRequest = useHttp();
const revealedPassword = ref<string | null>(null);
const passwordFailed = ref(false);

const revealPassword = async () => {
    passwordFailed.value = false;

    try {
        const response = (await passwordRequest.submit(
            password(props.server.uuid),
        )) as { password: string | null };
        revealedPassword.value = response.password;
    } catch {
        passwordFailed.value = true;
    }
};

// A password reset, reinstall or rebuild sets a new password: the one shown is
// hidden again whenever such a task starts or finishes.
const passwordTask = computed(() => {
    const task = props.tasks.find((candidate) =>
        ['reset_password', 'reinstall', 'create'].includes(candidate.type),
    );

    return task ? `${task.uuid}:${task.status}` : null;
});

watch(passwordTask, () => (revealedPassword.value = null));

const modelLabel =
    NETWORK_MODELS.find((model) => model.value === props.server.network_model)
        ?.label ?? props.server.network_model;

// Overview, apps, firewall, SSH keys and tasks on their own tabs. The tab survives the
// redirect back after an action (e.g. installing an app) for this server.
type Tab = 'overview' | 'apps' | 'firewall' | 'ssh-keys' | 'tasks';
const tabKey = `server-tab:${props.server.uuid}`;

// The firewall can be changed while the server is active or suspended (docs/adr/0035).
const hasFirewall = computed(() =>
    ['active', 'suspended'].includes(props.server.status),
);
// SSH keys only while it is active: the guest agent writes them (docs/adr/0049).
const hasSshKeys = computed(() => props.server.status === 'active');

const storedTab = (): Tab | null => {
    try {
        const value = sessionStorage.getItem(tabKey);

        return value === 'overview' ||
            value === 'apps' ||
            value === 'tasks' ||
            (value === 'firewall' && hasFirewall.value) ||
            (value === 'ssh-keys' && hasSshKeys.value)
            ? value
            : null;
    } catch {
        return null;
    }
};
const tab = ref<Tab>(
    props.revealedAppOutputs ? 'apps' : (storedTab() ?? 'overview'),
);

// A server leaving the active or suspended states (e.g. terminated) loses the tab.
watch(hasFirewall, (value) => {
    if (!value && tab.value === 'firewall') {
        tab.value = 'overview';
    }
});
watch(hasSshKeys, (value) => {
    if (!value && tab.value === 'ssh-keys') {
        tab.value = 'overview';
    }
});

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
    ...(hasFirewall.value
        ? [
              {
                  value: 'firewall' as Tab,
                  label: 'Firewall',
                  count: props.firewall.rules.length,
              },
          ]
        : []),
    ...(hasSshKeys.value
        ? [
              {
                  value: 'ssh-keys' as Tab,
                  label: 'SSH keys',
                  count: props.sshKeys.keys.length,
              },
          ]
        : []),
    { value: 'tasks' as Tab, label: 'Tasks', count: null },
]);

const runningTasks = computed(() =>
    props.tasks.filter(
        (task) => task.status === 'queued' || task.status === 'running',
    ),
);

// The keys change once this task succeeded (docs/adr/0049).
const changingSshKeys = computed(() =>
    runningTasks.value.some((task) => task.type === 'change_ssh_keys'),
);
// The keys are not polled: reloaded once, when such a task has finished.
watch(changingSshKeys, (changing, before) => {
    if (before && !changing) {
        router.reload({ only: ['sshKeys'] });
    }
});

const active = computed(() => props.server.status === 'active');
// Where the VM is, as Proxmox says (only asked for active servers).
const vmState = computed(() =>
    active.value ? (props.vm?.state ?? 'present') : 'present',
);
// Not on the node the panel recorded: most operations would fail there.
const vmAway = computed(() => vmState.value !== 'present');
const vmStopped = computed(
    () => active.value && !vmAway.value && props.power === 'stopped',
);
// Operations on the VM are refused while a task runs or the VM is not where the panel recorded it.
const vmBlocked = computed(() => busy.value || vmAway.value);
// English keys, translated by StatusBadge.
const vmBadge = computed(
    () =>
        ({
            present: '',
            missing: 'Not found in Proxmox',
            elsewhere: 'On another node',
            moving: 'Move not finished',
        })[vmState.value],
);
// Password reset, apps and scripts need the agent; a VM not running cannot have one.
const agentDown = computed(
    () => vmAway.value || vmStopped.value || props.agent === false,
);
// A firewall drift says it was changed in Proxmox; an adopted VM not compared yet may never have had it (docs/adr/0033).
const firewallDrift = computed(() =>
    props.drifts.some((drift) => drift.type === 'firewall_mismatch'),
);
const offerAntiSpoofing = computed(
    () =>
        ['active', 'suspended'].includes(props.server.status) &&
        (firewallDrift.value ||
            (props.server.origin === 'adopted' &&
                props.server.firewall_checked_at === null)),
);
const antiSpoofingOn = computed(
    () => props.server.firewall_checked_at !== null && !firewallDrift.value,
);

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
                <StatusBadge
                    v-if="active && vmAway"
                    :tone="vmState === 'moving' ? 'warning' : 'danger'"
                    :label="vmBadge"
                    data-test="vm-away"
                />
                <PowerControls
                    v-else-if="active"
                    :power="power"
                    :busy="busy"
                    :action="powerAction"
                />
                <Button v-if="active && !vmAway" size="sm" as-child>
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
                <RebuildDialog
                    v-if="server.can_rebuild"
                    :action="rebuild(server.uuid)"
                    :hostname="server.hostname"
                />
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
            v-if="server.status === 'suspended'"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            data-test="suspension"
        >
            <template v-if="server.suspension_cause === 'traffic'">
                {{
                    $t(
                        'Suspended by the panel over its traffic quota. It is unsuspended when the next period starts or its quota is raised.',
                    )
                }}
            </template>
            <template v-else-if="server.suspension_cause === 'usage'">
                {{
                    $t(
                        'Suspended by the panel over a usage limit. It stays suspended until you or the billing system unsuspend it.',
                    )
                }}
            </template>
            <template v-else>{{ $t('Suspended.') }}</template>
            <span v-if="server.suspension_reason" class="block font-medium">{{
                server.suspension_reason
            }}</span>
        </p>

        <p
            v-if="vmState === 'missing'"
            class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm"
            data-test="vm-missing"
        >
            {{
                $t(
                    'Proxmox has no VM :vmid in this cluster: it was deleted outside the panel. The next sync records it under drift, where you can mark the server as failed. Reinstall builds it again.',
                    { vmid: String(server.vmid) },
                )
            }}
        </p>

        <p
            v-else-if="vmState === 'elsewhere'"
            class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm"
            data-test="vm-elsewhere"
        >
            {{
                $t(
                    'Proxmox has VM :vmid on node :actual, not on :node: it was migrated outside the panel. The next sync records it under drift, where Accept Proxmox values takes the new node over.',
                    {
                        vmid: String(server.vmid),
                        actual: vm?.node ?? '—',
                        node: server.node,
                    },
                )
            }}
        </p>

        <p
            v-else-if="vmState === 'moving'"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            data-test="vm-moving"
        >
            {{
                $t(
                    'A move of this server has not finished: its VM is no longer on :node. If the move failed, Finish the move under Placement and billing completes it.',
                    { node: server.node },
                )
            }}
        </p>

        <p
            v-else-if="vmStopped"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            data-test="vm-stopped"
        >
            {{
                $t(
                    'The server is not running in Proxmox. Start it to reset its password or install apps.',
                )
            }}
        </p>

        <p
            v-else-if="active && agent === false"
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

            <!-- Live figures from Proxmox while its VM exists (docs/adr/0036). -->
            <ServerStatsCards
                v-if="
                    ['active', 'suspended'].includes(server.status) && !vmAway
                "
                :server-uuid="server.uuid"
                :port-speed-mbps="server.port_speed_mbps"
            />

            <div class="grid gap-4 lg:grid-cols-3">
                <section class="flex flex-col gap-3 rounded-xl border p-4">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-medium">{{ $t('Resources') }}</h3>
                        <ResourcesDialog
                            v-if="active"
                            :action="resources(server.uuid)"
                            :options="resourceOptions"
                            :disabled="vmBlocked"
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
                            <dd
                                v-else-if="server.has_password"
                                class="flex flex-col items-start gap-1"
                            >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="passwordRequest.processing"
                                    data-test="reveal-password"
                                    @click="revealPassword"
                                >
                                    <Eye class="h-4 w-4" />
                                    {{ $t('Show') }}
                                </Button>
                                <span
                                    v-if="passwordFailed"
                                    class="text-xs text-destructive"
                                    >{{
                                        $t('The password could not be shown.')
                                    }}</span
                                >
                            </dd>
                            <dd v-else>—</dd>
                        </div>
                        <div v-if="active">
                            <dt class="text-muted-foreground">
                                {{ $t('Guest agent') }}
                            </dt>
                            <dd class="font-medium">
                                <template v-if="vmAway || vmStopped">{{
                                    $t('Server not running')
                                }}</template>
                                <template v-else-if="agent === undefined">{{
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
                            :disabled="busy || agentDown"
                        />
                        <HostnameDialog
                            :action="changeHostname(server.uuid)"
                            :hostname="server.hostname"
                            :platform="server.platform"
                            :disabled="vmBlocked"
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
                    <!-- Moving to another node, cluster or location (docs/adr/0037). -->
                    <MoveHistory
                        v-if="moveOptions.moves.length"
                        :moves="moveOptions.moves"
                        :finish="(move) => finishMove([server.uuid, move])"
                        :disabled="busy"
                    />
                    <div
                        v-if="['active', 'suspended'].includes(server.status)"
                        class="mt-auto flex flex-wrap gap-2"
                    >
                        <MoveDialog
                            :action="moveServer(server.uuid)"
                            :options="moveOptions"
                            :hostname="server.hostname"
                            :disabled="vmBlocked"
                        />
                    </div>
                </section>
            </div>

            <section class="flex flex-col gap-3 rounded-xl border p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-medium">
                        {{ $t('IP addresses') }}
                        <span class="text-sm font-normal text-muted-foreground"
                            >· {{ $t(modelLabel) }}</span
                        >
                        <StatusBadge
                            v-if="antiSpoofingOn"
                            class="ml-2 align-middle"
                            tone="success"
                            label="Anti-spoofing on"
                            :title="
                                $t('Checked :time', {
                                    time: formatDateTime(
                                        server.firewall_checked_at,
                                    ),
                                })
                            "
                        />
                        <StatusBadge
                            v-else-if="firewallDrift"
                            class="ml-2 align-middle"
                            tone="warning"
                            label="Anti-spoofing off"
                        />
                    </h3>
                    <div
                        v-if="active || offerAntiSpoofing"
                        class="flex flex-wrap gap-2"
                    >
                        <AntiSpoofingButton
                            v-if="offerAntiSpoofing"
                            :action="enableAntiSpoofing(server.uuid)"
                            :addresses="
                                server.addresses.map(
                                    (address) => address.address,
                                )
                            "
                            :disabled="busy"
                        />
                        <ReapplyAddressesButton
                            v-if="active"
                            :action="reapplyAddresses(server.uuid)"
                            :disabled="
                                vmBlocked || server.addresses.length === 0
                            "
                        />
                        <AddressesDialog
                            v-if="active"
                            :action="changeAddresses(server.uuid)"
                            :addresses="server.addresses"
                            :pools="addressPools"
                            :disabled="vmBlocked"
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
                            :disabled="vmBlocked"
                        />
                    </li>
                    <li v-if="server.addresses.length === 0">—</li>
                </ul>
            </section>

            <UsageSection
                v-if="['active', 'suspended'].includes(server.status)"
                :usage="usage"
                :exempt="server.usage_exempt"
                :exempt-action="usageExempt(server.uuid)"
            />

            <!-- Only the billing system's reinstalls are limited (docs/adr/0045). -->
            <ReinstallLimitSection
                v-if="
                    server.billing_system &&
                    ['active', 'suspended'].includes(server.status)
                "
                :limit="reinstallLimit"
                :action="updateReinstallLimit(server.uuid)"
                :reset-action="resetReinstalls(server.uuid)"
            />

            <ServerChecks
                v-if="active || server.verification"
                :verification="server.verification"
                :action="active ? verify(server.uuid) : undefined"
                :disabled="vmBlocked"
            />

            <ServerScripts
                v-if="scripts.length || scriptRuns.length"
                :server-uuid="server.uuid"
                :hostname="server.hostname"
                :scripts="scripts"
                :runs="scriptRuns"
                :disabled="!active || vmBlocked || agentDown"
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
                    <SuspendDialog
                        v-if="active"
                        :action="suspend(server.uuid)"
                        :disabled="vmBlocked"
                    />
                    <!-- Suspended by the panel itself: an administrator may make it theirs (docs/adr/0039 §8). -->
                    <SuspendDialog
                        v-if="
                            server.status === 'suspended' &&
                            server.suspension_cause
                        "
                        take-over
                        :action="suspend(server.uuid)"
                        :disabled="busy"
                    />
                    <UnsuspendDialog
                        v-if="server.status === 'suspended'"
                        :action="unsuspend(server.uuid)"
                        :disabled="busy"
                    />
                    <ReinstallDialog
                        v-if="active"
                        :action="reinstall(server.uuid)"
                        :hostname="server.hostname"
                        :options="reinstallOptions"
                        :ssh-keys="
                            sshKeys.keys.map((key) => key.public_key).join('\n')
                        "
                        :disabled="busy || (vmAway && vmState !== 'missing')"
                    />
                    <!-- Deleting would leave a VM moved to another node running there. -->
                    <ConfirmDelete
                        v-if="!['elsewhere', 'moving'].includes(vmState)"
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
                :disabled="!active || agentDown"
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
                        :disabled="vmBlocked"
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

        <FirewallSection
            v-else-if="tab === 'firewall'"
            :server="server.uuid"
            :firewall="firewall"
            :disabled="vmBlocked"
        />

        <SshKeysSection
            v-else-if="tab === 'ssh-keys'"
            :server="server.uuid"
            :ssh-keys="sshKeys"
            :changing="changingSshKeys"
            :disabled="vmBlocked || agentDown"
        />

        <TaskList v-else :tasks="tasks" />
    </div>
</template>
