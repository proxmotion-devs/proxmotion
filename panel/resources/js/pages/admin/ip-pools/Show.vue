<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    Eraser,
    Lock,
    LockOpen,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AddressesField from '@/components/admin/AddressesField.vue';
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
    bulk,
    destroy as destroyAddress,
    keepOnly,
    store as storeAddresses,
    update as updateAddress,
} from '@/routes/admin/ip-addresses';
import {
    destroy,
    index,
    rdnsExport,
    rdnsMarkApplied,
    show,
} from '@/routes/admin/ip-pools';
import { show as showServer } from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type {
    IpAddress,
    IpPool,
    LocationOption,
    Option,
    Paginated,
} from '@/types/admin';

type Address = IpAddress & {
    server: {
        uuid: string;
        hostname: string;
        mac_address: string | null;
    } | null;
    ptr: string | null;
    ptr_pending: boolean;
};

const props = defineProps<{
    pool: IpPool;
    counts: { total: number; assigned: number; reserved: number };
    search: string;
    protected: Record<string, string>;
    addresses: Paginated<Address>;
    dnsProviders: Option[];
    rdns: { provider: string | null; record_only: boolean; pending: number };
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
const free = computed(
    () => props.counts.total - props.counts.assigned - props.counts.reserved,
);

// Search
const searchText = ref(props.search);
const runSearch = () => {
    router.visit(
        show(props.pool.uuid, {
            query: searchText.value ? { search: searchText.value } : {},
        }),
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};

// Selection for bulk actions (current page only)
const selected = ref<number[]>([]);
watch(
    () => props.addresses.data,
    () => (selected.value = []),
);
const allSelected = computed(
    () =>
        props.addresses.data.length > 0 &&
        selected.value.length === props.addresses.data.length,
);
const toggleAll = (value: boolean | 'indeterminate') => {
    selected.value =
        value === true ? props.addresses.data.map((address) => address.id) : [];
};
const toggleOne = (id: number, value: boolean | 'indeterminate') => {
    selected.value =
        value === true
            ? [...selected.value, id]
            : selected.value.filter((existing) => existing !== id);
};
const runBulk = (action: 'delete' | 'reserve' | 'unreserve') => {
    router.visit(bulk(props.pool.uuid), {
        data: { action, ids: selected.value },
        preserveScroll: true,
    });
};

// Adding addresses
const addOpen = ref(false);
const addForm = useForm({ addresses: '' });
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

// Keep only these
const keepOpen = ref(false);
const keepForm = useForm({ addresses: '' });
const openKeep = () => {
    keepForm.reset();
    keepForm.clearErrors();
    keepOpen.value = true;
};
const submitKeep = () => {
    keepForm.submit(keepOnly(props.pool.uuid), {
        preserveScroll: true,
        onSuccess: () => (keepOpen.value = false),
    });
};

// Editing one address
const editAddressOpen = ref(false);
const editingAddress = ref<Address | null>(null);
const addressForm = useForm({ is_reserved: false, mac_address: '', notes: '' });
const editAddress = (address: Address) => {
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
                <Button @click="openAdd">
                    <Plus class="h-4 w-4" />
                    {{ $t('Add addresses') }}
                </Button>
                <Button variant="outline" @click="openKeep">
                    <Eraser class="h-4 w-4" />
                    {{ $t('Keep only listed addresses') }}
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
            <div>
                <dt class="text-muted-foreground">{{ $t('Addresses') }}</dt>
                <dd class="font-medium">{{ counts.total }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Free') }}</dt>
                <dd class="font-medium">{{ free }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Assigned') }}</dt>
                <dd class="font-medium">{{ counts.assigned }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ $t('Reserved') }}</dt>
                <dd class="font-medium">{{ counts.reserved }}</dd>
            </div>
            <div
                v-if="Object.keys(protected).length"
                class="sm:col-span-2 lg:col-span-4"
            >
                <dt class="text-muted-foreground">
                    {{ $t('Never assigned to servers') }}
                </dt>
                <dd class="flex flex-wrap gap-2 font-mono text-xs">
                    <span
                        v-for="(reason, address) in protected"
                        :key="address"
                        class="rounded-md border px-2 py-0.5"
                    >
                        {{ address }}
                        <span class="text-muted-foreground"
                            >({{ $t(reason) }})</span
                        >
                    </span>
                </dd>
            </div>
        </dl>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <form class="flex gap-2" @submit.prevent="runSearch">
                <Input
                    v-model="searchText"
                    class="w-60 font-mono"
                    :placeholder="$t('Search address or note')"
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="icon"
                    :aria-label="$t('Search')"
                >
                    <Search class="h-4 w-4" />
                </Button>
            </form>
            <div
                v-if="selected.length"
                class="flex flex-wrap items-center gap-2 text-sm"
            >
                <span class="text-muted-foreground">{{
                    $t(':count selected', { count: String(selected.length) })
                }}</span>
                <Button variant="outline" size="sm" @click="runBulk('reserve')">
                    <Lock class="h-4 w-4" />
                    {{ $t('Reserve') }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="runBulk('unreserve')"
                >
                    <LockOpen class="h-4 w-4" />
                    {{ $t('Unreserve') }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    @click="runBulk('delete')"
                >
                    <Trash2 class="h-4 w-4" />
                    {{ $t('Delete') }}
                </Button>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm"
        >
            <div>
                <p class="font-medium">{{ $t('Reverse DNS') }}</p>
                <p class="text-muted-foreground">
                    {{
                        rdns.record_only
                            ? $t(
                                  'Record only: PTRs are kept here; export them and apply them by hand.',
                              )
                            : $t('Published through :provider in :zone.', {
                                  provider: rdns.provider ?? '',
                                  zone: pool.reverse_zone ?? '',
                              })
                    }}
                    <template v-if="rdns.pending > 0">
                        ·
                        {{
                            $t(':count pending', {
                                count: String(rdns.pending),
                            })
                        }}
                    </template>
                </p>
            </div>
            <div v-if="rdns.record_only" class="flex flex-wrap gap-2">
                <Button as-child variant="outline" size="sm">
                    <a :href="rdnsExport(pool.uuid).url">{{
                        $t('Export CSV')
                    }}</a>
                </Button>
                <Button
                    v-if="rdns.pending > 0"
                    variant="outline"
                    size="sm"
                    @click="
                        router.visit(rdnsMarkApplied(pool.uuid), {
                            preserveScroll: true,
                        })
                    "
                >
                    {{ $t('Mark all as applied') }}
                </Button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="w-10 px-4 py-2">
                            <Checkbox
                                :model-value="allSelected"
                                :aria-label="$t('Select all on this page')"
                                @update:model-value="toggleAll"
                            />
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ $t('Address') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ $t('MAC address') }}
                        </th>
                        <th class="px-4 py-2 font-medium">{{ $t('Notes') }}</th>
                        <th class="px-4 py-2 font-medium">
                            {{ $t('Reverse DNS') }}
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
                        <td class="px-4 py-2">
                            <Checkbox
                                :model-value="selected.includes(address.id)"
                                :aria-label="
                                    $t('Select :address', {
                                        address: address.address,
                                    })
                                "
                                @update:model-value="
                                    (value) => toggleOne(address.id, value)
                                "
                            />
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ address.address }}
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                v-if="address.server"
                                :href="showServer(address.server.uuid)"
                                class="text-xs hover:underline"
                            >
                                <StatusBadge tone="success" label="Assigned" />
                                {{ address.server.hostname }}
                            </Link>
                            <StatusBadge
                                v-else-if="address.is_reserved"
                                tone="warning"
                                label="Reserved"
                            />
                            <StatusBadge v-else tone="neutral" label="Free" />
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            <template v-if="address.mac_address">
                                {{ address.mac_address }}
                            </template>
                            <span
                                v-else-if="address.server?.mac_address"
                                class="text-muted-foreground"
                                :title="
                                    $t('Generated by Proxmox for the server')
                                "
                            >
                                {{ address.server.mac_address }}
                            </span>
                            <template v-else>—</template>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ address.notes ?? '' }}
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ address.ptr ?? '' }}
                            <span
                                v-if="address.ptr_pending"
                                class="text-amber-700 dark:text-amber-400"
                                >({{ $t('pending') }})</span
                            >
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
                                    v-if="!address.server"
                                    :action="destroyAddress(address.id)"
                                    :name="address.address"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="addresses.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            {{
                                $t(
                                    search
                                        ? 'No addresses match the search.'
                                        : 'No addresses in this pool.',
                                )
                            }}
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
        :dns-providers="dnsProviders"
    />

    <FormDialog
        v-model:open="addOpen"
        title="Add addresses"
        description="Gateway, network/broadcast addresses and Proxmox node IPs are skipped automatically, as are addresses that already exist."
        submit-label="Add"
        :processing="addForm.processing"
        @submit="submitAdd"
    >
        <AddressesField
            v-model="addForm.addresses"
            :error="addForm.errors.addresses"
            :ipv6="pool.version === 6"
        />
    </FormDialog>

    <FormDialog
        v-model:open="keepOpen"
        title="Keep only listed addresses"
        description="Paste the addresses that belong to you. Every other unassigned address of this pool is deleted. Addresses assigned to servers are always kept."
        submit-label="Delete the others"
        :processing="keepForm.processing"
        @submit="submitKeep"
    >
        <AddressesField
            v-model="keepForm.addresses"
            :error="keepForm.errors.addresses"
            :ipv6="pool.version === 6"
            label="Addresses to keep"
        />
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
