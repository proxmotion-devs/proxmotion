<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import Heading from '@/components/Heading.vue';
import OrderedAppFields from '@/components/servers/OrderedAppFields.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatMib } from '@/lib/format';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import { create, index, store } from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type { NetworkModel, Option, OrderedAppOption } from '@/types/admin';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Servers', href: index() },
            { title: 'Create server', href: create() },
        ],
    },
});

const props = defineProps<{
    billingSystems: Option[];
    locations: Option[];
    packages: (Option & { network_models: NetworkModel[] })[];
    osTemplates: Option[];
    apps: OrderedAppOption[];
    nodes: NodeOption[];
}>();

type NodeOption = Option & {
    location: string;
    online: boolean;
    maintenance: boolean;
    storages: {
        name: string;
        type: string;
        shared: boolean;
        use_for_servers: boolean;
        free_mib: number;
    }[];
};

const form = useForm({
    billing_system: '' as string | null,
    external_id: '',
    external_client_id: '',
    location: props.locations[0]?.value ?? null,
    // Without packages, custom resources are the only option.
    resource_mode: (props.packages.length > 0 ? 'package' : 'custom') as
        | 'package'
        | 'custom'
        | null,
    package: props.packages[0]?.value ?? null,
    cpu_cores: 1,
    memory_mib: 1024,
    disk_gib: 20,
    ipv4_count: 1,
    ipv6_count: 0,
    port_speed_mbps: '' as OptionalNumber,
    traffic_gb: '' as OptionalNumber,
    placement: 'auto' as 'auto' | 'node' | null,
    node: null as string | null,
    storage: '',
    os_template: props.osTemplates[0]?.value ?? null,
    network_model: null as NetworkModel | null,
    hostname: '',
    primary_ipv4: '',
    root_password: '',
    ssh_keys: '',
    app: '',
    app_inputs: {} as Record<string, string | number | boolean>,
});

const custom = computed(() => form.resource_mode === 'custom');

// "" = none: the server belongs to no billing system and has no billing ids.
const billingSystemOptions = computed(() => [
    { value: '', label: trans('None') },
    ...props.billingSystems,
]);

const resourceModes = computed(() => [
    { value: 'package' as const, label: trans('From a package') },
    { value: 'custom' as const, label: trans('Custom resources') },
]);

// Packages limit the network models; custom servers may use any (NAT comes later).
const modelOptions = computed(() => {
    const allowed = custom.value
        ? NETWORK_MODELS.map((model) => model.value)
        : (props.packages.find((pkg) => pkg.value === form.package)
              ?.network_models ?? []);

    return NETWORK_MODELS.filter(
        (model) => allowed.includes(model.value) && model.value !== 'nat',
    ).map((model) => ({
        value: model.value,
        label: trans(model.label),
    }));
});

watch(
    modelOptions,
    (options) => {
        if (!options.some((option) => option.value === form.network_model)) {
            form.network_model = options[0]?.value ?? null;
        }
    },
    { immediate: true },
);

const placements = computed(() => [
    {
        value: 'auto' as const,
        label: trans('Automatic (best node of the location)'),
    },
    { value: 'node' as const, label: trans('Choose the node and storage') },
]);

const locationNodes = computed(() =>
    props.nodes.filter((node) => node.location === form.location),
);

const nodeOptions = computed(() =>
    locationNodes.value.map((node) => ({
        value: node.value,
        label: !node.online
            ? trans(':node — offline', { node: node.label })
            : node.maintenance
              ? trans(':node — maintenance', { node: node.label })
              : node.label,
    })),
);

watch(
    locationNodes,
    (nodes) => {
        if (!nodes.some((node) => node.value === form.node)) {
            form.node = nodes[0]?.value ?? null;
        }
    },
    { immediate: true },
);

// "" = the storage of the node with the most room among those used for servers.
const storageOptions = computed(() => [
    { value: '', label: trans('Automatic (most free space)') },
    ...(
        props.nodes.find((node) => node.value === form.node)?.storages ?? []
    ).map((storage) => ({
        value: storage.name,
        label: trans(':storage (:type) — :free free', {
            storage: storage.name,
            type: storage.shared
                ? `${storage.type}, ${trans('shared')}`
                : storage.type,
            free: formatMib(storage.free_mib),
        }),
    })),
]);

watch(
    () => form.node,
    () => {
        if (
            !storageOptions.value.some(
                (option) => option.value === form.storage,
            )
        ) {
            form.storage = '';
        }
    },
);

const missing = computed(
    () => props.locations.length === 0 || props.osTemplates.length === 0,
);

// General errors (no capacity, Proxmox unreachable…) come back under the "server" key.
const serverError = computed(
    () => (form.errors as Record<string, string | undefined>).server,
);

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            port_speed_mbps: optionalNumber(data.port_speed_mbps),
            traffic_gb: optionalNumber(data.traffic_gb),
        }))
        .submit(store());
</script>

<template>
    <Head title="Create server" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Create server"
            description="The best node of the location is chosen automatically unless you choose one. Creation continues in the background."
        />

        <p
            v-if="missing"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            {{ $t('You need at least one location and active OS template.') }}
        </p>

        <form class="grid gap-4" @submit.prevent="submit">
            <InputError :message="serverError" />

            <FormField
                label="Billing system"
                for="billing_system"
                :error="form.errors.billing_system"
                hint="Needed for billing IDs. Only this billing system's API tokens can manage the server."
            >
                <NativeSelect
                    id="billing_system"
                    v-model="form.billing_system"
                    :options="billingSystemOptions"
                />
            </FormField>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Billing service ID"
                    for="external_id"
                    :error="form.errors.external_id"
                    hint="Optional. The service ID in your billing system."
                >
                    <Input
                        id="external_id"
                        v-model="form.external_id"
                        autocomplete="off"
                    />
                </FormField>
                <FormField
                    label="Billing client ID"
                    for="external_client_id"
                    :error="form.errors.external_client_id"
                    hint="Optional. The customer's ID in your billing system."
                >
                    <Input
                        id="external_client_id"
                        v-model="form.external_client_id"
                        autocomplete="off"
                    />
                </FormField>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Location"
                    for="location"
                    :error="form.errors.location"
                >
                    <NativeSelect
                        id="location"
                        v-model="form.location"
                        :options="locations"
                    />
                </FormField>
                <FormField
                    label="Resources"
                    for="resource_mode"
                    :error="form.errors.resource_mode"
                >
                    <NativeSelect
                        id="resource_mode"
                        v-model="form.resource_mode"
                        :options="resourceModes"
                    />
                </FormField>
            </div>

            <FormField
                v-if="!custom"
                label="Package"
                for="package"
                :error="form.errors.package"
            >
                <NativeSelect
                    id="package"
                    v-model="form.package"
                    :options="packages"
                    placeholder="Choose a package"
                />
            </FormField>

            <fieldset v-else class="grid gap-4 rounded-lg border p-4">
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
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
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
                </div>
            </fieldset>

            <FormField
                label="Placement"
                for="placement"
                :error="form.errors.placement"
                hint="Automatic placement uses only storages marked “Use for servers”. A chosen node may also be in maintenance."
            >
                <NativeSelect
                    id="placement"
                    v-model="form.placement"
                    :options="placements"
                />
            </FormField>

            <div
                v-if="form.placement === 'node'"
                class="grid gap-4 sm:grid-cols-2"
            >
                <FormField label="Node" for="node" :error="form.errors.node">
                    <NativeSelect
                        id="node"
                        v-model="form.node"
                        :options="nodeOptions"
                        placeholder="No node in this location"
                    />
                </FormField>
                <FormField
                    label="Storage"
                    for="storage"
                    :error="form.errors.storage"
                    hint="Free space after over-commit and the disks already booked."
                >
                    <NativeSelect
                        id="storage"
                        v-model="form.storage"
                        :options="storageOptions"
                    />
                </FormField>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Operating system"
                    for="os_template"
                    :error="form.errors.os_template"
                >
                    <NativeSelect
                        id="os_template"
                        v-model="form.os_template"
                        :options="osTemplates"
                    />
                </FormField>
                <FormField
                    label="Network model"
                    for="network_model"
                    :error="form.errors.network_model"
                >
                    <NativeSelect
                        id="network_model"
                        v-model="form.network_model"
                        :options="modelOptions"
                    />
                </FormField>
            </div>

            <OrderedAppFields
                v-model:app="form.app"
                v-model:inputs="form.app_inputs"
                :options="apps"
                :os-template="form.os_template"
                :errors="form.errors as Record<string, string>"
            />

            <FormField
                label="Hostname"
                for="hostname"
                :error="form.errors.hostname"
            >
                <Input
                    id="hostname"
                    v-model="form.hostname"
                    required
                    class="font-mono"
                    placeholder="web1.example.com"
                />
            </FormField>

            <FormField
                label="Primary IPv4 address"
                for="primary_ipv4"
                :error="form.errors.primary_ipv4"
                hint="Optional. A free address of this location's pools; empty takes the next free one."
            >
                <Input
                    id="primary_ipv4"
                    v-model="form.primary_ipv4"
                    class="font-mono"
                    autocomplete="off"
                    placeholder="203.0.113.10"
                />
            </FormField>

            <FormField
                label="Root password"
                for="root_password"
                :error="form.errors.root_password"
                hint="Leave empty to generate a random password. At least 12 characters."
            >
                <Input
                    id="root_password"
                    v-model="form.root_password"
                    type="password"
                    autocomplete="new-password"
                />
            </FormField>

            <FormField
                label="SSH public keys"
                for="ssh_keys"
                :error="form.errors.ssh_keys"
                hint="Optional. One key per line."
            >
                <textarea
                    id="ssh_keys"
                    v-model="form.ssh_keys"
                    rows="3"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    placeholder="ssh-ed25519 AAAA… user@host"
                />
            </FormField>

            <div>
                <Button type="submit" :disabled="form.processing || missing">
                    <Spinner v-if="form.processing" />
                    {{ $t('Create server') }}
                </Button>
            </div>
        </form>
    </div>
</template>
