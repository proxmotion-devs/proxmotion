<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import IpPoolFormDialog from '@/components/admin/IpPoolFormDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy as destroyAddress,
    store as storeAddresses,
    update as updateAddress,
} from '@/routes/admin/ip-addresses';
import { destroy, index, show } from '@/routes/admin/ip-pools';
import { NETWORK_MODELS } from '@/types/admin';
import type {
    IpAddress,
    IpPool,
    LocationOption,
    Paginated,
} from '@/types/admin';

const props = defineProps<{
    pool: IpPool;
    addresses: Paginated<IpAddress>;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'IP pools', href: index() },
        { title: props.pool.name, href: show(props.pool.uuid) },
    ],
});

const locations: LocationOption[] = [props.pool.location];
const editOpen = ref(false);
const modelLabel =
    NETWORK_MODELS.find((model) => model.value === props.pool.network_model)
        ?.label ?? props.pool.network_model;

// Adding addresses: a range for IPv4, a single address for IPv6.
const addOpen = ref(false);
const addForm = useForm({ from: '', to: '', address: '' });

const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    addOpen.value = true;
};

const submitAdd = () => {
    addForm.submit(storeAddresses(props.pool.uuid), {
        preserveScroll: true,
        onSuccess: () => (addOpen.value = false),
    });
};

// Editing one address
const editAddressOpen = ref(false);
const editingAddress = ref<IpAddress | null>(null);
const addressForm = useForm({ is_reserved: false, mac_address: '', notes: '' });

const editAddress = (address: IpAddress) => {
    editingAddress.value = address;
    addressForm.clearErrors();
    addressForm.defaults({
        is_reserved: address.is_reserved,
        mac_address: address.mac_address ?? '',
        notes: address.notes ?? '',
    });
    addressForm.reset();
    editAddressOpen.value = true;
};

const submitAddress = () => {
    if (!editingAddress.value) {
        return;
    }

    addressForm.submit(updateAddress(editingAddress.value.id), {
        preserveScroll: true,
        onSuccess: () => (editAddressOpen.value = false),
    });
};

const toggleReserved = (
    address: IpAddress,
    value: boolean | 'indeterminate',
) => {
    router.visit(updateAddress(address.id), {
        data: {
            is_reserved: value === true,
            mac_address: address.mac_address,
            notes: address.notes,
        },
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="pool.name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="pool.name"
                :description="`${pool.network} · IPv${pool.version}`"
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="openAdd">
                    <Plus class="h-4 w-4" />
                    {{ $t('Add addresses') }}
                </Button>
                <Button variant="outline" @click="editOpen = true">
                    <Pencil class="h-4 w-4" />
                    {{ $t('Edit') }}
                </Button>
                <ConfirmDelete
                    with-label
                    :action="destroy(pool.uuid)"
                    :name="pool.name"
                    description="The pool and all of its addresses are deleted."
                />
            </div>
        </div>

        <dl
            class="grid gap-4 rounded-xl border p-4 text-sm sm:grid-cols-2 lg:grid-cols-4"
        >
            <div>
                <dt class="text-muted-foreground">{{ $t('Location') }}</dt>
                <dd class="font-medium">{{ pool.location.name }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Network model') }}</dt>
                <dd class="font-medium">{{ $t(modelLabel) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Gateway') }}</dt>
                <dd class="font-mono text-xs">{{ pool.gateway ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Bridge') }}</dt>
                <dd class="font-mono text-xs">
                    {{ pool.bridge
                    }}<template v-if="pool.vlan_tag">
                        · VLAN {{ pool.vlan_tag }}</template
                    >
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground">{{ $t('Nameservers') }}</dt>
                <dd class="font-mono text-xs">
                    {{ pool.nameservers.join(', ') || '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Status') }}</dt>
                <dd>
                    <StatusBadge
                        :tone="pool.is_active ? 'success' : 'neutral'"
                        :label="pool.is_active ? 'Active' : 'Inactive'"
                    />
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Addresses') }}</dt>
                <dd class="font-medium">{{ addresses.total }}</dd>
            </div>
        </dl>

        <p
            v-if="pool.version === 6"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            {{
                $t(
                    'IPv6 addresses and prefixes are assigned per server when servers are created. You can add individual addresses here if needed.',
                )
            }}
        </p>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ $t('Address') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ $t('MAC address') }}
                        </th>
                        <th class="px-4 py-2 font-medium">{{ $t('Notes') }}</th>
                        <th class="px-4 py-2 font-medium">
                            {{ $t('Reserved') }}
                        </th>
                        <th class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="address in addresses.data"
                        :key="address.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ address.address }}
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ address.mac_address ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ address.notes ?? '' }}
                        </td>
                        <td class="px-4 py-2">
                            <Checkbox
                                :model-value="address.is_reserved"
                                :aria-label="
                                    $t('Reserve :address', {
                                        address: address.address,
                                    })
                                "
                                @update:model-value="
                                    (value) => toggleReserved(address, value)
                                "
                            />
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        $t('Edit :name', {
                                            name: address.address,
                                        })
                                    "
                                    @click="editAddress(address)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <ConfirmDelete
                                    :action="destroyAddress(address.id)"
                                    :name="address.address"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="addresses.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            {{ $t('No addresses in this pool.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="addresses.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in addresses.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="{
                        'bg-primary text-primary-foreground': link.active,
                    }"
                >
                    <span v-html="link.label" />
                </Link>
            </template>
        </nav>
    </div>

    <IpPoolFormDialog
        v-model:open="editOpen"
        :pool="pool"
        :locations="locations"
    />

    <FormDialog
        v-model:open="addOpen"
        title="Add addresses"
        :description="
            pool.version === 4
                ? 'Adds every address in the range. Existing addresses and the gateway are skipped.'
                : undefined
        "
        submit-label="Add"
        :processing="addForm.processing"
        @submit="submitAdd"
    >
        <div v-if="pool.version === 4" class="grid gap-4 sm:grid-cols-2">
            <FormField label="From" for="from" :error="addForm.errors.from">
                <Input
                    id="from"
                    v-model="addForm.from"
                    required
                    class="font-mono"
                    placeholder="203.0.113.10"
                />
            </FormField>
            <FormField label="To" for="to" :error="addForm.errors.to">
                <Input
                    id="to"
                    v-model="addForm.to"
                    required
                    class="font-mono"
                    placeholder="203.0.113.20"
                />
            </FormField>
        </div>
        <FormField
            v-else
            label="Address"
            for="address"
            :error="addForm.errors.address"
        >
            <Input
                id="address"
                v-model="addForm.address"
                required
                class="font-mono"
                placeholder="2001:db8:100::10"
            />
        </FormField>
    </FormDialog>

    <FormDialog
        v-model:open="editAddressOpen"
        :title="editingAddress?.address ?? ''"
        :processing="addressForm.processing"
        @submit="submitAddress"
    >
        <Label class="flex items-center gap-3 font-normal">
            <Checkbox v-model="addressForm.is_reserved" />
            {{ $t('Reserved (never assigned to new servers)') }}
        </Label>
        <FormField
            label="MAC address"
            for="mac_address"
            :error="addressForm.errors.mac_address"
            hint="Only for routed IPs that need a provider-issued virtual MAC."
        >
            <Input
                id="mac_address"
                v-model="addressForm.mac_address"
                class="font-mono"
                placeholder="02:00:00:aa:bb:cc"
            />
        </FormField>
        <FormField label="Notes" for="notes" :error="addressForm.errors.notes">
            <Input id="notes" v-model="addressForm.notes" />
        </FormField>
    </FormDialog>
</template>
