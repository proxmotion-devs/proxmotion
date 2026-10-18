<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import GuideBox from '@/components/admin/GuideBox.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMib, formatTraffic } from '@/lib/format';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import { destroy, index, store, update } from '@/routes/admin/packages';
import { NETWORK_MODELS } from '@/types/admin';
import type { NetworkModel, Package } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Packages', href: index() }] },
});

type ClusterChoice = { id: number; name: string; location: string };

const props = defineProps<{ packages: Package[]; clusters: ClusterChoice[] }>();

// Clusters grouped by location for the checkboxes.
const clustersByLocation = computed(() => {
    const groups = new Map<string, ClusterChoice[]>();

    for (const cluster of props.clusters) {
        groups.set(cluster.location, [
            ...(groups.get(cluster.location) ?? []),
            cluster,
        ]);
    }

    return [...groups.entries()];
});

const clusterNames = (pkg: Package) =>
    props.clusters
        .filter((cluster) => pkg.cluster_ids.includes(cluster.id))
        .map((cluster) => cluster.name)
        .join(', ');

const toggleCluster = (id: number, value: boolean | 'indeterminate') => {
    form.cluster_ids =
        value === true
            ? [...form.cluster_ids, id]
            : form.cluster_ids.filter((existing) => existing !== id);
};

const dialogOpen = ref(false);
const editing = ref<Package | null>(null);

const form = useForm({
    name: '',
    cpu_cores: 2,
    memory_mib: 4096,
    disk_gib: 40,
    traffic_gb: 2000 as OptionalNumber,
    port_speed_mbps: 1000 as OptionalNumber,
    ipv4_count: 1,
    ipv6_count: 1,
    snapshot_slots: 1,
    backup_slots: 1,
    network_models: ['bridged'] as NetworkModel[],
    is_active: true,
    sort_order: 0,
    cluster_ids: [] as number[],
});

const openForm = (pkg: Package | null) => {
    editing.value = pkg;
    form.clearErrors();
    form.defaults(
        pkg
            ? {
                  name: pkg.name,
                  cpu_cores: pkg.cpu_cores,
                  memory_mib: pkg.memory_mib,
                  disk_gib: Math.round(pkg.disk_mib / 1024),
                  traffic_gb:
                      pkg.traffic_bytes === null
                          ? ''
                          : Math.round(pkg.traffic_bytes / 1000 ** 3),
                  port_speed_mbps: pkg.port_speed_mbps ?? '',
                  ipv4_count: pkg.ipv4_count,
                  ipv6_count: pkg.ipv6_count,
                  snapshot_slots: pkg.snapshot_slots,
                  backup_slots: pkg.backup_slots,
                  network_models: [...pkg.network_models],
                  is_active: pkg.is_active,
                  sort_order: pkg.sort_order,
                  cluster_ids: [...pkg.cluster_ids],
              }
            : {
                  name: '',
                  cpu_cores: 2,
                  memory_mib: 4096,
                  disk_gib: 40,
                  traffic_gb: 2000,
                  port_speed_mbps: 1000,
                  ipv4_count: 1,
                  ipv6_count: 1,
                  snapshot_slots: 1,
                  backup_slots: 1,
                  network_models: ['bridged'],
                  is_active: true,
                  sort_order: 0,
                  cluster_ids: [],
              },
    );
    form.reset();
    dialogOpen.value = true;
};

const toggleModel = (model: NetworkModel, value: boolean | 'indeterminate') => {
    form.network_models =
        value === true
            ? [...form.network_models, model]
            : form.network_models.filter((existing) => existing !== model);
};

const networkModelErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('network_models'))
        .map(([, message]) => message)
        .join(' '),
);

const submit = () => {
    form.transform((data) => ({
        ...data,
        traffic_gb: optionalNumber(data.traffic_gb),
        port_speed_mbps: optionalNumber(data.port_speed_mbps),
    })).submit(editing.value ? update(editing.value.uuid) : store(), {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
};

const modelLabel = (value: string) =>
    NETWORK_MODELS.find((model) => model.value === value)?.label ?? value;
</script>

<template>
    <Head title="Packages" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Packages"
                description="VPS plans with their resources and limits. Billing systems sell these packages."
            />
            <Button @click="openForm(null)">
                <Plus class="h-4 w-4" />
                {{ $t('Add package') }}
            </Button>
        </div>

        <GuideBox
            id="packages"
            title="How packages are used"
            topic="getting-started"
        >
            <ul>
                <li>
                    {{
                        $t(
                            "A package is a plan: the billing system's product names it, and new servers get its resources.",
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Changing a package does not change existing servers. Use Change resources on a server, or the upgrade in the billing system.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Apps are only offered when the package has the resources they need.',
                        )
                    }}
                </li>
            </ul>
        </GuideBox>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('CPU') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Memory') }}
                        </th>
                        <th class="px-4 py-3 font-medium">{{ $t('Disk') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Traffic') }}
                        </th>
                        <th class="px-4 py-3 font-medium">{{ $t('IPs') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Network models') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Clusters') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="pkg in packages"
                        :key="pkg.uuid"
                        class="border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ pkg.name }}</span>
                            <StatusBadge
                                v-if="!pkg.is_active"
                                tone="neutral"
                                label="Inactive"
                                class="ml-2"
                            />
                        </td>
                        <td class="px-4 py-3">
                            {{
                                $t(':count vCPU', {
                                    count: String(pkg.cpu_cores),
                                })
                            }}
                        </td>
                        <td class="px-4 py-3">
                            {{ formatMib(pkg.memory_mib) }}
                        </td>
                        <td class="px-4 py-3">{{ formatMib(pkg.disk_mib) }}</td>
                        <td class="px-4 py-3">
                            {{ formatTraffic(pkg.traffic_bytes) }}
                            <span
                                v-if="pkg.port_speed_mbps"
                                class="text-muted-foreground"
                                >· {{ pkg.port_speed_mbps }} Mbps</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            {{ pkg.ipv4_count }}× IPv4<template
                                v-if="pkg.ipv6_count"
                                >, {{ pkg.ipv6_count }}× IPv6</template
                            >
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{
                                pkg.network_models
                                    .map((model) => $t(modelLabel(model)))
                                    .join(', ')
                            }}
                        </td>
                        <td
                            class="px-4 py-3 text-xs"
                            data-test="package-clusters"
                        >
                            {{
                                pkg.cluster_ids.length
                                    ? clusterNames(pkg)
                                    : $t('All clusters')
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        $t('Edit :name', { name: pkg.name })
                                    "
                                    @click="openForm(pkg)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <ConfirmDelete
                                    :action="destroy(pkg.uuid)"
                                    :name="pkg.name"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="packages.length === 0">
                        <td
                            colspan="9"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ $t('No packages yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        :title="editing ? 'Edit package' : 'Add package'"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField label="Name" for="name" :error="form.errors.name">
            <Input id="name" v-model="form.name" required placeholder="VPS M" />
        </FormField>
        <div class="grid gap-4 sm:grid-cols-3">
            <FormField
                label="vCPU cores"
                for="cpu_cores"
                :error="form.errors.cpu_cores"
            >
                <Input
                    id="cpu_cores"
                    v-model.number="form.cpu_cores"
                    type="number"
                    min="1"
                    required
                />
            </FormField>
            <FormField
                label="Memory (MiB)"
                for="memory_mib"
                :error="form.errors.memory_mib"
            >
                <Input
                    id="memory_mib"
                    v-model.number="form.memory_mib"
                    type="number"
                    min="128"
                    step="128"
                    required
                />
            </FormField>
            <FormField
                label="Disk (GiB)"
                for="disk_gib"
                :error="form.errors.disk_gib"
            >
                <Input
                    id="disk_gib"
                    v-model.number="form.disk_gib"
                    type="number"
                    min="1"
                    required
                />
            </FormField>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="Monthly traffic (GB)"
                for="traffic_gb"
                :error="form.errors.traffic_gb"
                hint="Leave empty for unlimited."
            >
                <Input
                    id="traffic_gb"
                    v-model.number="form.traffic_gb"
                    type="number"
                    min="1"
                />
            </FormField>
            <FormField
                label="Port speed (Mbps)"
                for="port_speed_mbps"
                :error="form.errors.port_speed_mbps"
                hint="Leave empty for unlimited."
            >
                <Input
                    id="port_speed_mbps"
                    v-model.number="form.port_speed_mbps"
                    type="number"
                    min="1"
                />
            </FormField>
        </div>
        <div class="grid gap-4 sm:grid-cols-4">
            <FormField
                label="IPv4 addresses"
                for="ipv4_count"
                :error="form.errors.ipv4_count"
            >
                <Input
                    id="ipv4_count"
                    v-model.number="form.ipv4_count"
                    type="number"
                    min="0"
                    required
                />
            </FormField>
            <FormField
                label="IPv6 addresses"
                for="ipv6_count"
                :error="form.errors.ipv6_count"
            >
                <Input
                    id="ipv6_count"
                    v-model.number="form.ipv6_count"
                    type="number"
                    min="0"
                    required
                />
            </FormField>
            <FormField
                label="Snapshots"
                for="snapshot_slots"
                :error="form.errors.snapshot_slots"
            >
                <Input
                    id="snapshot_slots"
                    v-model.number="form.snapshot_slots"
                    type="number"
                    min="0"
                    required
                />
            </FormField>
            <FormField
                label="Backups"
                for="backup_slots"
                :error="form.errors.backup_slots"
            >
                <Input
                    id="backup_slots"
                    v-model.number="form.backup_slots"
                    type="number"
                    min="0"
                    required
                />
            </FormField>
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('Network models') }}</Label>
            <div class="flex flex-wrap gap-4">
                <Label
                    v-for="model in NETWORK_MODELS"
                    :key="model.value"
                    class="flex items-center gap-2 font-normal"
                >
                    <Checkbox
                        :model-value="form.network_models.includes(model.value)"
                        @update:model-value="
                            (value) => toggleModel(model.value, value)
                        "
                    />
                    {{ $t(model.label) }}
                </Label>
            </div>
            <p
                v-if="networkModelErrors"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ networkModelErrors }}
            </p>
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('Clusters') }}</Label>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'Servers of this package are only placed on the clusters you tick, e.g. to keep plans on one CPU type. Tick none to use any cluster of the ordered location.',
                    )
                }}
            </p>
            <div
                v-for="[location, locationClusters] in clustersByLocation"
                :key="location"
                class="rounded-lg border p-3"
            >
                <div class="mb-2 text-sm font-medium">{{ location }}</div>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <Label
                        v-for="cluster in locationClusters"
                        :key="cluster.id"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="form.cluster_ids.includes(cluster.id)"
                            @update:model-value="
                                (value) => toggleCluster(cluster.id, value)
                            "
                        />
                        {{ cluster.name }}
                    </Label>
                </div>
            </div>
            <p
                v-if="form.errors.cluster_ids"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ form.errors.cluster_ids }}
            </p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="Sort order"
                for="sort_order"
                :error="form.errors.sort_order"
            >
                <Input
                    id="sort_order"
                    v-model.number="form.sort_order"
                    type="number"
                    min="0"
                />
            </FormField>
            <Label class="flex items-center gap-3 self-end pb-2 font-normal">
                <Checkbox v-model="form.is_active" />
                {{ $t('Active (can be sold)') }}
            </Label>
        </div>
    </FormDialog>
</template>
