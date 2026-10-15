<script setup lang="ts">
import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import {
    AlertTriangle,
    CircleCheck,
    CircleX,
    Pencil,
    RefreshCw,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ClusterFormDialog from '@/components/admin/ClusterFormDialog.vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { useClusterSync } from '@/composables/useClusterSync';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes, formatDateTime, percentage } from '@/lib/format';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import { destroy, index, permissions, show } from '@/routes/admin/clusters';
import { update as updateNode } from '@/routes/admin/nodes';
import { update as updateStorage } from '@/routes/admin/storages';
import type {
    Cluster,
    LocationOption,
    Node,
    NodeStatus,
    PermissionCheck,
    Storage,
} from '@/types/admin';

const props = defineProps<{
    cluster: Cluster;
    locations: LocationOption[];
    nodes: Node[];
    templates: { vmid: number; name: string | null; node: string }[];
    permissionCheck: PermissionCheck | null;
}>();

const checkPermissions = () =>
    router.visit(permissions(props.cluster.uuid), { preserveScroll: true });

setLayoutProps({
    breadcrumbs: [
        { title: 'Clusters', href: index() },
        { title: props.cluster.name, href: show(props.cluster.uuid) },
    ],
});

const page = usePage();
const editOpen = ref(false);

const statusTone: Record<NodeStatus, 'success' | 'danger' | 'neutral'> = {
    online: 'success',
    offline: 'danger',
    unknown: 'neutral',
};
const statusLabel: Record<NodeStatus, string> = {
    online: 'Online',
    offline: 'Offline',
    unknown: 'Not reported',
};

const clusterSync = useClusterSync();
const syncing = computed(
    () => clusterSync.syncing.value === props.cluster.uuid,
);
const startSync = () => clusterSync.startSync(props.cluster.uuid);

const toggleStorage = (storage: Storage, value: boolean | 'indeterminate') => {
    router.visit(updateStorage(storage.id), {
        data: { use_for_servers: value === true },
        preserveScroll: true,
    });
};

// Node placement settings
const nodeDialogOpen = ref(false);
const editingNode = ref<Node | null>(null);
const nodeForm = useForm({
    is_maintenance: false,
    cpu_overcommit: 4,
    memory_overcommit: 1,
    disk_overcommit: 1,
    max_servers: '' as OptionalNumber,
});

const editNode = (node: Node) => {
    editingNode.value = node;
    nodeForm.clearErrors();
    nodeForm.defaults({
        is_maintenance: node.is_maintenance,
        cpu_overcommit: node.cpu_overcommit,
        memory_overcommit: node.memory_overcommit,
        disk_overcommit: node.disk_overcommit,
        max_servers: node.max_servers ?? '',
    });
    nodeForm.reset();
    nodeDialogOpen.value = true;
};

const submitNode = () => {
    if (!editingNode.value) {
        return;
    }

    nodeForm
        .transform((data) => ({
            ...data,
            max_servers: optionalNumber(data.max_servers),
        }))
        .submit(updateNode(editingNode.value.uuid), {
            preserveScroll: true,
            onSuccess: () => (nodeDialogOpen.value = false),
        });
};
</script>

<template>
    <Head :title="cluster.name" />

    <div class="flex flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-2">
                <Heading
                    :title="cluster.name"
                    description="Nodes, storages, bridges and templates are read from Proxmox every five minutes."
                />
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    :disabled="syncing"
                    @click="startSync"
                >
                    <RefreshCw
                        class="h-4 w-4"
                        :class="{ 'animate-spin': syncing }"
                    />
                    {{ $t('Sync now') }}
                </Button>
                <Button variant="outline" @click="editOpen = true">
                    <Pencil class="h-4 w-4" />
                    {{ $t('Edit') }}
                </Button>
                <ConfirmDelete
                    with-label
                    :action="destroy(cluster.uuid)"
                    :name="cluster.name"
                    description="The cluster is only removed from the panel. Nothing is changed on Proxmox."
                />
            </div>
        </div>

        <div
            v-if="cluster.last_sync_error"
            class="flex gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
        >
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <div class="font-medium">{{ $t('The last sync failed') }}</div>
                <div class="break-all">{{ cluster.last_sync_error }}</div>
            </div>
        </div>

        <dl
            class="grid gap-4 rounded-xl border p-4 text-sm sm:grid-cols-2 lg:grid-cols-4"
        >
            <div>
                <dt class="text-muted-foreground">{{ $t('Location') }}</dt>
                <dd class="font-medium">{{ cluster.location.name }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Type') }}</dt>
                <dd class="font-medium">
                    <template v-if="cluster.type === 'cluster'">
                        {{ $t('Cluster') }} ({{ cluster.proxmox_cluster_name }})
                    </template>
                    <template v-else-if="cluster.type">{{
                        $t('Standalone node')
                    }}</template>
                    <template v-else>—</template>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Proxmox VE') }}</dt>
                <dd class="font-medium">{{ cluster.pve_version ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Last sync') }}</dt>
                <dd class="font-medium">
                    {{
                        formatDateTime(
                            cluster.last_synced_at,
                            page.props.locale,
                        )
                    }}
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground">{{ $t('API hosts') }}</dt>
                <dd class="font-mono text-xs">
                    {{
                        cluster.hosts
                            .map((host) => `${host}:${cluster.port}`)
                            .join(', ')
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('API token ID') }}</dt>
                <dd class="font-mono text-xs">{{ cluster.token_id }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('TLS') }}</dt>
                <dd>
                    <StatusBadge
                        :tone="cluster.verify_tls ? 'success' : 'warning'"
                        :label="
                            cluster.verify_tls ? 'Verified' : 'Not verified'
                        "
                    />
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Resource pool') }}</dt>
                <dd class="font-mono text-xs">
                    {{ cluster.resource_pool ?? '/vms' }}
                </dd>
            </div>
        </dl>

        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    variant="small"
                    title="API token permissions"
                    description="Checks on Proxmox whether the token has every privilege the panel needs."
                />
                <Button variant="outline" @click="checkPermissions">
                    <ShieldCheck class="h-4 w-4" />
                    {{ $t('Check permissions') }}
                </Button>
            </div>
            <ul v-if="permissionCheck" class="flex flex-col gap-2 text-sm">
                <li
                    v-for="entry in permissionCheck"
                    :key="entry.path"
                    class="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                >
                    <CircleX
                        v-if="entry.missing.length"
                        class="h-4 w-4 text-red-600"
                    />
                    <AlertTriangle
                        v-else-if="entry.excess.length"
                        class="h-4 w-4 text-amber-600"
                    />
                    <CircleCheck v-else class="h-4 w-4 text-emerald-600" />
                    <span class="font-mono text-xs">{{ entry.path }}</span>
                    <span class="text-muted-foreground">{{
                        $t(entry.purpose)
                    }}</span>
                    <span
                        v-if="entry.missing.length"
                        class="w-full font-mono text-xs text-red-700 dark:text-red-400"
                    >
                        {{
                            $t('Missing: :privileges', {
                                privileges: entry.missing.join(', '),
                            })
                        }}
                    </span>
                    <span
                        v-if="entry.excess.length"
                        class="w-full font-mono text-xs text-amber-700 dark:text-amber-400"
                    >
                        {{
                            $t('More than needed: :privileges', {
                                privileges: entry.excess.join(', '),
                            })
                        }}
                    </span>
                </li>
            </ul>
        </section>

        <section class="flex flex-col gap-4">
            <Heading variant="small" title="Nodes" />

            <p
                v-if="nodes.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                {{
                    $t(
                        'No nodes yet. They appear after the first successful sync.',
                    )
                }}
            </p>

            <article
                v-for="node in nodes"
                :key="node.uuid"
                class="flex flex-col gap-4 rounded-xl border p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-mono text-base font-semibold">
                            {{ node.name }}
                        </h3>
                        <StatusBadge
                            :tone="statusTone[node.status]"
                            :label="statusLabel[node.status]"
                        />
                        <StatusBadge
                            v-if="node.is_maintenance"
                            tone="warning"
                            label="Maintenance"
                        />
                    </div>
                    <Button variant="outline" size="sm" @click="editNode(node)">
                        <Pencil class="h-4 w-4" />
                        {{ $t('Placement settings') }}
                    </Button>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('CPU cores') }}
                        </dt>
                        <dd>
                            {{ node.cpu_cores ?? '—' }}
                            <span class="text-muted-foreground"
                                >× {{ node.cpu_overcommit }}</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('Memory') }}
                        </dt>
                        <dd>
                            {{ formatBytes(node.memory_bytes) }}
                            <span class="text-muted-foreground"
                                >× {{ node.memory_overcommit }}</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('Disk over-commit') }}
                        </dt>
                        <dd>× {{ node.disk_overcommit }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('Max servers') }}
                        </dt>
                        <dd>{{ node.max_servers ?? $t('No limit') }}</dd>
                    </div>
                </dl>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">
                                    {{ $t('Storage') }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ $t('Type') }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ $t('Content') }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ $t('Usage') }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ $t('Use for servers') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="storage in node.storages"
                                :key="storage.id"
                                class="border-t"
                                :class="{ 'opacity-50': !storage.is_available }"
                            >
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ storage.name }}
                                    <StatusBadge
                                        v-if="storage.is_shared"
                                        tone="neutral"
                                        label="Shared"
                                        class="ml-1"
                                    />
                                    <StatusBadge
                                        v-if="!storage.is_available"
                                        tone="danger"
                                        label="Unavailable"
                                        class="ml-1"
                                    />
                                </td>
                                <td class="px-3 py-2">{{ storage.type }}</td>
                                <td
                                    class="px-3 py-2 text-xs text-muted-foreground"
                                >
                                    {{ storage.content.join(', ') }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ formatBytes(storage.used_bytes) }} /
                                    {{ formatBytes(storage.total_bytes) }}
                                    <span
                                        v-if="
                                            percentage(
                                                storage.used_bytes,
                                                storage.total_bytes,
                                            ) !== null
                                        "
                                        class="text-muted-foreground"
                                    >
                                        ({{
                                            percentage(
                                                storage.used_bytes,
                                                storage.total_bytes,
                                            )
                                        }}%)
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <Checkbox
                                        v-if="storage.supports_vm_disks"
                                        :model-value="storage.use_for_servers"
                                        :aria-label="
                                            $t('Use :name for server disks', {
                                                name: storage.name,
                                            })
                                        "
                                        @update:model-value="
                                            (value) =>
                                                toggleStorage(storage, value)
                                        "
                                    />
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ $t('No VM disks') }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="node.storages.length === 0">
                                <td
                                    colspan="5"
                                    class="px-3 py-4 text-center text-muted-foreground"
                                >
                                    {{ $t('No storages reported.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="text-muted-foreground"
                        >{{ $t('Bridges') }}:</span
                    >
                    <span
                        v-for="bridge in node.bridges"
                        :key="bridge.name"
                        class="rounded-md border px-2 py-0.5 font-mono"
                        :class="{ 'opacity-50': !bridge.is_active }"
                        :title="
                            [bridge.cidr, bridge.comments]
                                .filter(Boolean)
                                .join(' — ')
                        "
                    >
                        {{ bridge.name }}
                        <template v-if="bridge.is_vlan_aware"> · VLAN</template>
                    </span>
                    <span
                        v-if="node.bridges.length === 0"
                        class="text-muted-foreground"
                        >—</span
                    >
                </div>
            </article>
        </section>

        <section class="flex flex-col gap-4">
            <Heading
                variant="small"
                title="VM templates"
                description="QEMU templates found on this cluster. Map them to OS templates on the OS templates page."
            />
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">VMID</th>
                            <th class="px-4 py-2 font-medium">
                                {{ $t('Name') }}
                            </th>
                            <th class="px-4 py-2 font-medium">
                                {{ $t('Node') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="template in templates"
                            :key="template.vmid"
                            class="border-t"
                        >
                            <td class="px-4 py-2 font-mono">
                                {{ template.vmid }}
                            </td>
                            <td class="px-4 py-2">
                                {{ template.name ?? '—' }}
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">
                                {{ template.node }}
                            </td>
                        </tr>
                        <tr v-if="templates.length === 0">
                            <td
                                colspan="3"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                {{ $t('No VM templates found.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <ClusterFormDialog
        v-model:open="editOpen"
        :cluster="cluster"
        :locations="locations"
    />

    <FormDialog
        v-model:open="nodeDialogOpen"
        title="Placement settings"
        description="Over-commit ratios decide how much of a node's capacity new servers may book."
        :processing="nodeForm.processing"
        @submit="submitNode"
    >
        <Label class="flex items-center gap-3 font-normal">
            <Checkbox v-model="nodeForm.is_maintenance" />
            {{ $t('Maintenance mode (no new servers are placed here)') }}
        </Label>
        <div class="grid gap-4 sm:grid-cols-3">
            <FormField
                label="CPU over-commit"
                for="cpu_overcommit"
                :error="nodeForm.errors.cpu_overcommit"
            >
                <Input
                    id="cpu_overcommit"
                    v-model.number="nodeForm.cpu_overcommit"
                    type="number"
                    step="0.1"
                    min="0.1"
                    required
                />
            </FormField>
            <FormField
                label="Memory over-commit"
                for="memory_overcommit"
                :error="nodeForm.errors.memory_overcommit"
            >
                <Input
                    id="memory_overcommit"
                    v-model.number="nodeForm.memory_overcommit"
                    type="number"
                    step="0.1"
                    min="0.1"
                    required
                />
            </FormField>
            <FormField
                label="Disk over-commit"
                for="disk_overcommit"
                :error="nodeForm.errors.disk_overcommit"
            >
                <Input
                    id="disk_overcommit"
                    v-model.number="nodeForm.disk_overcommit"
                    type="number"
                    step="0.1"
                    min="0.1"
                    required
                />
            </FormField>
        </div>
        <FormField
            label="Max servers"
            for="max_servers"
            :error="nodeForm.errors.max_servers"
            hint="Leave empty for no limit."
        >
            <Input
                id="max_servers"
                v-model.number="nodeForm.max_servers"
                type="number"
                min="0"
            />
        </FormField>
    </FormDialog>
</template>
