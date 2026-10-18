<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import Heading from '@/components/Heading.vue';
import Ipv4Fields from '@/components/servers/Ipv4Fields.vue';
import OrderedAppFields from '@/components/servers/OrderedAppFields.vue';
import PlacementFields from '@/components/servers/PlacementFields.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import { create, index, store } from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type {
    Ipv4PoolOption,
    NetworkModel,
    Option,
    OrderedAppOption,
    PlacementNode,
} from '@/types/admin';

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
    packages: (Option & {
        network_models: NetworkModel[];
        ipv4_count: number;
        placement_groups: string[];
    })[];
    placementGroups: (Option & { location: string })[];
    ipv4Pools: Ipv4PoolOption[];
    osTemplates: Option[];
    apps: OrderedAppOption[];
    nodes: PlacementNode[];
}>();

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
    placement: 'group' as 'group' | 'node' | null,
    placement_group: null as string | null,
    node: null as string | null,
    storage: '',
    os_template: props.osTemplates[0]?.value ?? null,
    network_model: null as NetworkModel | null,
    hostname: '',
    ipv4_pool: '',
    ipv4_addresses: [] as string[],
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

// A package may limit its servers to some placement groups (none = any);
// custom servers may go anywhere.
const chosenPackage = computed(() =>
    custom.value
        ? undefined
        : props.packages.find((pkg) => pkg.value === form.package),
);

// How many IPv4 addresses the server gets, for choosing them.
const ipv4Count = computed(() =>
    custom.value
        ? Number(form.ipv4_count) || 0
        : (chosenPackage.value?.ipv4_count ?? 0),
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
            description="Place the server in a placement group or on a single node. Creation continues in the background."
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

            <PlacementFields
                v-model:placement="form.placement"
                v-model:group="form.placement_group"
                v-model:node="form.node"
                v-model:storage="form.storage"
                :nodes="nodes"
                :groups="placementGroups"
                :location="form.location"
                :package-groups="chosenPackage?.placement_groups ?? []"
                :errors="form.errors as Record<string, string>"
            />

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

            <Ipv4Fields
                v-model:pool="form.ipv4_pool"
                v-model:addresses="form.ipv4_addresses"
                :pools="ipv4Pools"
                :location="form.location"
                :network-model="form.network_model"
                :count="ipv4Count"
                :errors="form.errors as Record<string, string>"
            />

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
