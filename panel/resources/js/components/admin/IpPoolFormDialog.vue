<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import AddressesField from '@/components/admin/AddressesField.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { trans } from 'laravel-vue-i18n';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import { store, update } from '@/routes/admin/ip-pools';
import { NETWORK_MODELS } from '@/types/admin';
import type { IpPool, LocationOption, NetworkModel } from '@/types/admin';

const props = defineProps<{
    pool: IpPool | null;
    locations: LocationOption[];
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    location: null as string | null,
    name: '',
    version: 4 as 4 | 6,
    network_model: 'bridged' as NetworkModel | null,
    network: '',
    gateway: '',
    bridge: 'vmbr0',
    vlan_tag: '' as OptionalNumber,
    nameservers: '',
    is_active: true,
    add_mode: 'list' as 'list' | 'network' | 'none' | null,
    addresses: '',
});

const addModeOptions = computed(() =>
    [
        { value: 'list' as const, label: trans('Only the addresses I list') },
        {
            value: 'network' as const,
            label: trans('Every address of the network'),
        },
        { value: 'none' as const, label: trans('None for now') },
    ].filter((option) => option.value !== 'network' || form.version === 4),
);

const locationOptions = computed(() =>
    props.locations.map((location) => ({
        value: location.uuid,
        label: location.name,
    })),
);
const modelOptions = computed(() =>
    NETWORK_MODELS.map((model) => ({
        value: model.value,
        label: trans(model.label),
    })),
);
const versionOptions = [
    { value: 4 as const, label: 'IPv4' },
    { value: 6 as const, label: 'IPv6' },
];

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const pool = props.pool;

    form.clearErrors();
    form.defaults({
        location: pool?.location.uuid ?? props.locations[0]?.uuid ?? null,
        name: pool?.name ?? '',
        version: pool?.version ?? 4,
        network_model: pool?.network_model ?? 'bridged',
        network: pool?.network ?? '',
        gateway: pool?.gateway ?? '',
        bridge: pool?.bridge ?? 'vmbr0',
        vlan_tag: pool?.vlan_tag ?? '',
        nameservers: pool?.nameservers.join('\n') ?? '',
        is_active: pool?.is_active ?? true,
        add_mode: 'list',
        addresses: '',
    });
    form.reset();
});

const nameserverErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('nameservers'))
        .map(([, message]) => message)
        .join(' '),
);

const submit = () => {
    form.transform((data) => ({
        ...data,
        vlan_tag: optionalNumber(data.vlan_tag),
        nameservers: data.nameservers
            .split(/[\s,]+/)
            .map((ip) => ip.trim())
            .filter(Boolean),
    })).submit(props.pool ? update(props.pool.uuid) : store(), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
};
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="pool ? 'Edit IP pool' : 'Add IP pool'"
        :description="
            pool
                ? 'Network, IP version and location cannot be changed after creation.'
                : 'The network is the subnet your addresses belong to. Only add the addresses that are yours.'
        "
        :processing="form.processing"
        @submit="submit"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="Location"
                for="location"
                :error="form.errors.location"
            >
                <NativeSelect
                    id="location"
                    v-model="form.location"
                    :options="locationOptions"
                    :disabled="!!pool"
                    placeholder="Choose a location"
                />
            </FormField>
            <FormField label="Name" for="name" :error="form.errors.name">
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    placeholder="fra1-public-v4"
                />
            </FormField>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="IP version"
                for="version"
                :error="form.errors.version"
            >
                <NativeSelect
                    id="version"
                    v-model="form.version"
                    :options="versionOptions"
                    :disabled="!!pool"
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
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="Network (CIDR)"
                for="network"
                :error="form.errors.network"
            >
                <Input
                    id="network"
                    v-model="form.network"
                    :disabled="!!pool"
                    required
                    class="font-mono"
                    :placeholder="
                        form.version === 4
                            ? '203.0.113.0/24'
                            : '2001:db8:100::/48'
                    "
                />
            </FormField>
            <FormField
                label="Gateway"
                for="gateway"
                :error="form.errors.gateway"
                :hint="
                    form.network_model === 'routed'
                        ? 'For routed IPs this is usually the host\'s gateway, outside the routed block.'
                        : undefined
                "
            >
                <Input
                    id="gateway"
                    v-model="form.gateway"
                    class="font-mono"
                    :placeholder="
                        form.version === 4 ? '203.0.113.1' : '2001:db8:100::1'
                    "
                />
            </FormField>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="Bridge"
                for="bridge"
                :error="form.errors.bridge"
                hint="Bridge on the nodes that servers are attached to."
            >
                <Input
                    id="bridge"
                    v-model="form.bridge"
                    required
                    class="font-mono"
                />
            </FormField>
            <FormField
                label="VLAN tag"
                for="vlan_tag"
                :error="form.errors.vlan_tag"
                hint="Optional."
            >
                <Input
                    id="vlan_tag"
                    v-model.number="form.vlan_tag"
                    type="number"
                    min="1"
                    max="4094"
                />
            </FormField>
        </div>
        <FormField
            label="Nameservers"
            for="nameservers"
            :error="nameserverErrors"
            hint="Up to four, one per line."
        >
            <textarea
                id="nameservers"
                v-model="form.nameservers"
                rows="2"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            />
        </FormField>
        <Label class="flex items-center gap-3 font-normal">
            <Checkbox v-model="form.is_active" />
            {{ $t('Active (used for new servers)') }}
        </Label>

        <template v-if="!pool">
            <FormField
                label="Which addresses should be added?"
                for="add_mode"
                :error="form.errors.add_mode"
            >
                <NativeSelect
                    id="add_mode"
                    v-model="form.add_mode"
                    :options="addModeOptions"
                />
            </FormField>
            <p
                v-if="form.add_mode === 'network'"
                class="text-xs text-amber-700 dark:text-amber-400"
            >
                {{
                    $t(
                        'Only choose this when the whole block is yours. Shared data-centre subnets contain other customers’ addresses.',
                    )
                }}
            </p>
            <AddressesField
                v-if="form.add_mode === 'list'"
                v-model="form.addresses"
                :error="form.errors.addresses"
                :ipv6="form.version === 6"
            />
        </template>
    </FormDialog>
</template>
