<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Check } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import Heading from '@/components/Heading.vue';
import CapacityNote from '@/components/servers/CapacityNote.vue';
import Ipv4Fields from '@/components/servers/Ipv4Fields.vue';
import OrderedAppFields from '@/components/servers/OrderedAppFields.vue';
import PackageFields from '@/components/servers/PackageFields.vue';
import PlacementFields from '@/components/servers/PlacementFields.vue';
import ResourceFields from '@/components/servers/ResourceFields.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatMib } from '@/lib/format';
import { optionalNumber } from '@/lib/forms';
import { hostnameExample } from '@/lib/hostnames';
import {
    firstStepWithError,
    makesTestServer,
    packageAllowing,
    templatesFor,
    WIZARD_STEPS,
} from '@/lib/serverWizard';
import { create, index, store } from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type {
    Ipv4PoolOption,
    NetworkModel,
    Option,
    OrderedAppOption,
    PackageOption,
    PlacementGroupOption,
    PlacementNode,
    NewServerResources,
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
    /** With a hostname template, the hostname may stay empty (docs/adr/0031). */
    locations: (Option & { code: string; hostname_template: string | null })[];
    packages: PackageOption[];
    placementGroups: PlacementGroupOption[];
    ipv4Pools: Ipv4PoolOption[];
    /** Inactive ones make a test server without a billing system (docs/adr/0024 §8). */
    osTemplates: (Option & { active: boolean })[];
    /** Preselected by ?os_template= (a template checked without a running server). */
    selectedOsTemplate?: string | null;
    apps: OrderedAppOption[];
    nodes: PlacementNode[];
}>();

// A preselected template (?os_template=) must survive: start with a package that allows it, else a custom server (docs/adr/0040).
const startPackage = props.selectedOsTemplate
    ? packageAllowing(props.packages, props.selectedOsTemplate)
    : props.packages[0]?.value;

const form = useForm({
    // "package": its resources, placed automatically; "custom": entered and placed by hand.
    resource_mode: (startPackage !== undefined ? 'package' : 'custom') as
        | 'package'
        | 'custom',
    location: props.locations[0]?.value ?? null,
    package: startPackage ?? '',
    placement: 'group' as 'group' | 'node' | null,
    placement_group: null as string | null,
    node: null as string | null,
    storage: '',
    resources: {
        cpu_cores: 1,
        memory_mib: 1024,
        disk_gib: 20,
        ipv4_count: 1,
        ipv6_count: 0,
        port_speed_mbps: '',
        traffic_gb: '',
        cpu_limit: '',
        cpu_units: '',
        disk_mb_s: '',
        disk_iops: '',
        snapshot_slots: 0,
        backup_slots: 0,
    } as NewServerResources,
    os_template:
        props.selectedOsTemplate ?? props.osTemplates[0]?.value ?? null,
    app: '',
    app_inputs: {} as Record<string, string | number | boolean>,
    network_model: null as NetworkModel | null,
    ipv4_pool: '',
    ipv4_addresses: [] as string[],
    hostname: '',
    root_password: '',
    ssh_keys: '',
    billing_system: '' as string | null,
    external_id: '',
    external_client_id: '',
});

const errors = computed(() => form.errors as Record<string, string>);

const step = ref(0);
// The furthest step reached; the step list links back to any of them.
const reached = ref(0);
const last = WIZARD_STEPS.length - 1;

const custom = computed(() => form.resource_mode === 'custom');

const modes = computed(() => [
    {
        value: 'package' as const,
        label: trans('Package (resources and placement from the package)'),
    },
    {
        value: 'custom' as const,
        label: trans('Custom (enter resources, choose a group or node)'),
    },
]);

const chosenLocation = computed(() =>
    props.locations.find((location) => location.value === form.location),
);

// Without a hostname, the server is named from its location's template (docs/adr/0031).
const hostnameTemplate = computed(
    () => chosenLocation.value?.hostname_template ?? '',
);

const generatedHostname = computed(() =>
    hostnameTemplate.value === ''
        ? ''
        : hostnameExample(
              hostnameTemplate.value,
              chosenLocation.value?.code ?? '',
          ),
);

const chosenPackage = computed(() =>
    custom.value
        ? undefined
        : props.packages.find((pkg) => pkg.value === form.package),
);

// The templates the chosen package's servers may use (docs/adr/0040).
const allowedOsTemplates = computed(() =>
    templatesFor(props.osTemplates, chosenPackage.value),
);

// Choosing a package that does not allow the selected template moves to one it does.
watch(
    allowedOsTemplates,
    (templates) => {
        if (!templates.some((option) => option.value === form.os_template)) {
            form.os_template = templates[0]?.value ?? null;
        }
    },
    { immediate: true },
);

// Packages limit the network models; without one any model goes (NAT comes later).
const modelOptions = computed(() => {
    const allowed =
        chosenPackage.value?.network_models ??
        NETWORK_MODELS.map((model) => model.value);

    return NETWORK_MODELS.filter(
        (model) => allowed.includes(model.value) && model.value !== 'nat',
    ).map((model) => ({ value: model.value, label: trans(model.label) }));
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

// The resources the server gets: the package's, or those entered.
const resources = computed<NewServerResources>(() => {
    const pkg = chosenPackage.value;

    return pkg
        ? {
              cpu_cores: pkg.cpu_cores,
              memory_mib: pkg.memory_mib,
              disk_gib: pkg.disk_gib,
              ipv4_count: pkg.ipv4_count,
              ipv6_count: pkg.ipv6_count,
              port_speed_mbps: pkg.port_speed_mbps ?? '',
              traffic_gb: pkg.traffic_gb ?? '',
              cpu_limit: pkg.cpu_limit ?? '',
              cpu_units: pkg.cpu_units ?? '',
              disk_mb_s: pkg.disk_mb_s ?? '',
              disk_iops: pkg.disk_iops ?? '',
              snapshot_slots: pkg.snapshot_slots,
              backup_slots: pkg.backup_slots,
          }
        : form.resources;
});

const size = computed(() => ({
    cpu_cores: Number(resources.value.cpu_cores) || 0,
    memory_mib: Number(resources.value.memory_mib) || 0,
    disk_mib: (Number(resources.value.disk_gib) || 0) * 1024,
}));

const ipv4Count = computed(() => Number(resources.value.ipv4_count) || 0);

// "" = none: the server belongs to no billing system and has no billing ids.
const billingSystemOptions = computed(() => [
    { value: '', label: trans('None') },
    ...props.billingSystems,
]);

// An inactive template is tried on a test server: no billing system, no billing ids.
const testServer = computed(() =>
    makesTestServer(props.osTemplates, form.os_template),
);

watch(testServer, (test) => {
    if (test) {
        form.billing_system = '';
        form.external_id = '';
        form.external_client_id = '';
    }
});

const missing = computed(
    () => props.locations.length === 0 || props.osTemplates.length === 0,
);

// What a step needs before the next one opens; the server checks the rest.
const complete = computed<boolean[]>(() => [
    form.location !== null,
    custom.value
        ? size.value.cpu_cores > 0 &&
          size.value.memory_mib > 0 &&
          size.value.disk_mib > 0 &&
          ipv4Count.value + (Number(form.resources.ipv6_count) || 0) > 0 &&
          (form.placement === 'group'
              ? form.placement_group !== null
              : form.node !== null)
        : chosenPackage.value !== undefined,
    form.os_template !== null,
    form.network_model !== null,
    form.hostname.trim() !== '' || hostnameTemplate.value !== '',
    true,
]);

const go = (target: number) => {
    if (target <= reached.value) {
        step.value = target;
    }
};

const next = () => {
    if (complete.value[step.value] && step.value < last) {
        step.value++;
        reached.value = Math.max(reached.value, step.value);
    }
};

const optionLabel = (options: Option[], value: string | null) =>
    options.find((option) => option.value === value)?.label ?? '—';

const limit = (value: number | '', unit: string) =>
    value === '' ? trans('unlimited') : `${value} ${unit}`;

// What the review step shows, as [label, value] rows.
const summary = computed(() => {
    const placementLabel = !custom.value
        ? trans('Automatic (from the package)')
        : form.placement === 'group'
          ? trans('Placement group: :name', {
                name: optionLabel(props.placementGroups, form.placement_group),
            })
          : trans('Single node: :name', {
                name: optionLabel(props.nodes, form.node),
            }) + (form.storage === '' ? '' : ` · ${form.storage}`);
    const r = resources.value;

    return [
        [trans('Location'), optionLabel(props.locations, form.location)],
        [trans('Placement'), placementLabel],
        [trans('Package'), chosenPackage.value?.label ?? trans('Custom')],
        [
            trans('Resources'),
            `${r.cpu_cores} vCPU · ${formatMib(Number(r.memory_mib))} · ${formatMib(Number(r.disk_gib) * 1024)}`,
        ],
        [
            trans('Network'),
            `${limit(r.port_speed_mbps, 'Mbps')} · ${limit(r.traffic_gb, 'GB')}`,
        ],
        [
            trans('Operating system'),
            optionLabel(props.osTemplates, form.os_template),
        ],
        [trans('App'), form.app === '' ? trans('No app') : form.app],
        [
            trans('IP addresses'),
            `${r.ipv4_count}× IPv4, ${r.ipv6_count}× IPv6` +
                (form.ipv4_addresses.length
                    ? ` · ${form.ipv4_addresses.join(', ')}`
                    : ''),
        ],
        [
            trans('Hostname'),
            form.hostname.trim() !== ''
                ? form.hostname
                : trans('From the location template: :template', {
                      template: hostnameTemplate.value,
                  }),
        ],
        [
            trans('Billing system'),
            testServer.value
                ? trans('None (test server)')
                : optionLabel(billingSystemOptions.value, form.billing_system),
        ],
    ];
});

const submit = () => {
    if (step.value !== last) {
        next();

        return;
    }

    // A package brings its resources and placement; a custom server sends its own.
    form.transform(
        ({
            resources: entered,
            placement,
            placement_group,
            node,
            storage,
            ...data
        }) =>
            data.resource_mode === 'package'
                ? data
                : {
                      ...data,
                      ...entered,
                      package: null,
                      placement,
                      placement_group,
                      node,
                      storage,
                      port_speed_mbps: optionalNumber(entered.port_speed_mbps),
                      traffic_gb: optionalNumber(entered.traffic_gb),
                      cpu_limit: optionalNumber(entered.cpu_limit),
                      cpu_units: optionalNumber(entered.cpu_units),
                      disk_mb_s: optionalNumber(entered.disk_mb_s),
                      disk_iops: optionalNumber(entered.disk_iops),
                  },
    ).submit(store(), {
        // Back to the first step with an error.
        onError: (problems) => {
            step.value = firstStepWithError(problems) ?? step.value;
        },
    });
};
</script>

<template>
    <Head title="Create server" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <Heading
            title="Create server"
            description="Create a server from a package, or with custom resources in a placement group or on a node you choose. Creation continues in the background."
        />

        <p
            v-if="missing"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            {{ $t('You need at least one location and active OS template.') }}
        </p>

        <ol class="flex flex-wrap gap-2" data-test="wizard-steps">
            <li v-for="(item, number) in WIZARD_STEPS" :key="item.key">
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-full border px-3 py-1 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    :class="
                        number === step
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-background'
                    "
                    :disabled="number > reached"
                    :aria-current="number === step ? 'step' : undefined"
                    @click="go(number)"
                >
                    <Check
                        v-if="number < reached && number !== step"
                        class="h-3.5 w-3.5"
                    />
                    <span v-else>{{ number + 1 }}</span>
                    {{ $t(item.title) }}
                </button>
            </li>
        </ol>

        <form class="grid gap-4" @submit.prevent="submit">
            <template v-if="step === 0">
                <FormField
                    label="Server type"
                    for="resource_mode"
                    :error="errors.resource_mode"
                    hint="A package server gets the package's resources and is placed like a billing order. A custom server gets the resources you enter, in a placement group or on a node you choose."
                >
                    <NativeSelect
                        id="resource_mode"
                        v-model="form.resource_mode"
                        :options="
                            packages.length > 0
                                ? modes
                                : modes.filter(
                                      (mode) => mode.value === 'custom',
                                  )
                        "
                    />
                </FormField>
                <FormField
                    label="Location"
                    for="location"
                    :error="errors.location"
                >
                    <NativeSelect
                        id="location"
                        v-model="form.location"
                        :options="locations"
                    />
                </FormField>
            </template>

            <template v-else-if="step === 1 && !custom">
                <PackageFields
                    v-model:package="form.package"
                    :packages="packages"
                    :groups="placementGroups"
                    :nodes="nodes"
                    :location="form.location"
                    :errors="errors"
                />
            </template>

            <template v-else-if="step === 1">
                <ResourceFields
                    v-model:values="form.resources"
                    :errors="errors"
                />
                <PlacementFields
                    v-model:placement="form.placement"
                    v-model:group="form.placement_group"
                    v-model:node="form.node"
                    v-model:storage="form.storage"
                    :nodes="nodes"
                    :groups="placementGroups"
                    :location="form.location"
                    :errors="errors"
                />
                <CapacityNote
                    :nodes="nodes"
                    :groups="placementGroups"
                    :placement="form.placement"
                    :group="form.placement_group"
                    :node="form.node"
                    :storage="form.storage"
                    :size="size"
                />
            </template>

            <template v-else-if="step === 2">
                <FormField
                    label="Operating system"
                    for="os_template"
                    :error="errors.os_template"
                >
                    <NativeSelect
                        id="os_template"
                        v-model="form.os_template"
                        :options="allowedOsTemplates"
                    />
                </FormField>
                <OrderedAppFields
                    v-model:app="form.app"
                    v-model:inputs="form.app_inputs"
                    :options="apps"
                    :os-template="form.os_template"
                    :errors="errors"
                />
            </template>

            <template v-else-if="step === 3">
                <FormField
                    label="Network model"
                    for="network_model"
                    :error="errors.network_model"
                >
                    <NativeSelect
                        id="network_model"
                        v-model="form.network_model"
                        :options="modelOptions"
                    />
                </FormField>
                <Ipv4Fields
                    v-model:pool="form.ipv4_pool"
                    v-model:addresses="form.ipv4_addresses"
                    :pools="ipv4Pools"
                    :location="form.location"
                    :network-model="form.network_model"
                    :count="ipv4Count"
                    :errors="errors"
                />
            </template>

            <template v-else-if="step === 4">
                <FormField
                    label="Hostname"
                    for="hostname"
                    :error="errors.hostname"
                >
                    <Input
                        id="hostname"
                        v-model="form.hostname"
                        :required="hostnameTemplate === ''"
                        class="font-mono"
                        :placeholder="generatedHostname || 'web1.example.com'"
                    />
                    <p
                        v-if="hostnameTemplate !== ''"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            $t(
                                'Leave empty to name the server from the location template :template, e.g. :example. Its primary addresses then get this name as PTR.',
                                {
                                    template: hostnameTemplate,
                                    example: generatedHostname,
                                },
                            )
                        }}
                    </p>
                </FormField>
                <FormField
                    label="Root password"
                    for="root_password"
                    :error="errors.root_password"
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
                    :error="errors.ssh_keys"
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
                <p
                    v-if="testServer"
                    class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                    data-test="test-server-note"
                >
                    {{
                        $t(
                            'The chosen OS template is inactive, so this becomes a test server without a billing system. Activate the template to offer it to customers.',
                        )
                    }}
                </p>
                <template v-else>
                    <FormField
                        label="Billing system"
                        for="billing_system"
                        :error="errors.billing_system"
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
                            :error="errors.external_id"
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
                            :error="errors.external_client_id"
                            hint="Optional. The customer's ID in your billing system."
                        >
                            <Input
                                id="external_client_id"
                                v-model="form.external_client_id"
                                autocomplete="off"
                            />
                        </FormField>
                    </div>
                </template>
            </template>

            <template v-else>
                <InputError :message="errors.server" />
                <dl
                    class="divide-y rounded-lg border text-sm"
                    data-test="review"
                >
                    <div
                        v-for="[label, value] in summary"
                        :key="label"
                        class="grid gap-1 px-4 py-2 sm:grid-cols-3"
                    >
                        <dt class="text-muted-foreground">{{ label }}</dt>
                        <dd class="sm:col-span-2">{{ value }}</dd>
                    </div>
                </dl>
                <CapacityNote
                    v-if="custom"
                    :nodes="nodes"
                    :groups="placementGroups"
                    :placement="form.placement"
                    :group="form.placement_group"
                    :node="form.node"
                    :storage="form.storage"
                    :size="size"
                />
            </template>

            <div class="flex items-center justify-between gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="step === 0"
                    @click="step--"
                >
                    {{ $t('Back') }}
                </Button>
                <Button
                    v-if="step < last"
                    type="submit"
                    :disabled="!complete[step]"
                >
                    {{ $t('Next') }}
                </Button>
                <Button
                    v-else
                    type="submit"
                    :disabled="form.processing || missing"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Create server') }}
                </Button>
            </div>
        </form>
    </div>
</template>
