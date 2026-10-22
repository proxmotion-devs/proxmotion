<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ListPlus, Network, X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { AddressPoolOption, ServerAddress } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Edit a server's addresses in one change (docs/adr/0028): remove some, add
 * the next free or a specific address of a pool on the server's network, and
 * choose the primary of each IP version: an address the server keeps (a swap)
 * or one being added (with the old one removed, a replacement).
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    addresses: ServerAddress[];
    pools: AddressPoolOption[];
    disabled?: boolean;
}>();

type Version = 4 | 6;
type Row = {
    /** Stable while rows are added and removed; "row:<id>" chooses it as the primary. */
    id: number;
    ip_version: Version;
    pool: string;
    specific: boolean;
    address: string;
};

const ROW = 'row:';

const open = ref(false);
const removed = ref<string[]>([]);
/** Per version: an address the server keeps, or "row:<id>" of an added one. */
const primary = ref<Record<Version, string | null>>({ 4: null, 6: null });
const rows = ref<Row[]>([]);
let nextRowId = 1;

const form = useForm({
    add: [] as {
        ip_version: Version;
        pool: string | null;
        address: string | null;
        primary: boolean;
    }[],
    remove: [] as string[],
    primary_ipv4: null as string | null,
    primary_ipv6: null as string | null,
});

const VERSIONS: Version[] = [4, 6];

const currentPrimary = (version: Version) =>
    props.addresses.find(
        (address) => address.version === version && address.primary,
    )?.address ?? null;

const poolVersions = computed(() =>
    VERSIONS.filter((version) =>
        props.pools.some((pool) => pool.version === version),
    ),
);
const versionOptions = computed(() =>
    poolVersions.value.map((version) => ({
        value: String(version),
        label: `IPv${version}`,
    })),
);
const poolOptions = (version: Version) => [
    { value: '', label: trans('Any pool of this network') },
    ...props.pools
        .filter((pool) => pool.version === version)
        .map((pool) => ({
            value: pool.value,
            label:
                pool.free === null
                    ? pool.label
                    : trans(':pool, :count free', {
                          pool: pool.label,
                          count: String(pool.free),
                      }),
        })),
];
const freeAddresses = (row: Row) =>
    props.pools
        .filter(
            (pool) =>
                pool.version === row.ip_version &&
                (row.pool === '' || pool.value === row.pool),
        )
        .flatMap((pool) => pool.addresses);

/** What the server would have of a version: the addresses it keeps and those being added. */
const primaryOptions = (version: Version) => [
    ...props.addresses
        .filter(
            (address) =>
                address.version === version &&
                !removed.value.includes(address.address),
        )
        .map((address) => ({ value: address.address, label: address.address })),
    ...rows.value
        .filter((row) => row.ip_version === version)
        .map((row) => ({
            value: `${ROW}${row.id}`,
            label:
                row.specific && row.address.trim() !== ''
                    ? trans(':address (added)', { address: row.address.trim() })
                    : trans('Next free address (added)'),
        })),
];

const versionsShown = computed(() =>
    VERSIONS.filter(
        (version) =>
            props.addresses.some((address) => address.version === version) ||
            rows.value.some((row) => row.ip_version === version),
    ),
);

/** A removed primary needs another one of its version. */
const missingPrimary = (version: Version) => {
    const chosen = primary.value[version];

    return (
        chosen === null ||
        !primaryOptions(version).some((option) => option.value === chosen)
    );
};

const toggleRemove = (address: string) => {
    removed.value = removed.value.includes(address)
        ? removed.value.filter((other) => other !== address)
        : [...removed.value, address];
};

const addRow = () =>
    rows.value.push({
        id: nextRowId++,
        ip_version: poolVersions.value[0] ?? 4,
        pool: '',
        specific: false,
        address: '',
    });

/** A row that leaves its version is no longer its primary. */
const unchoose = (row: Row) => {
    if (primary.value[row.ip_version] === `${ROW}${row.id}`) {
        primary.value[row.ip_version] = currentPrimary(row.ip_version);
    }
};

const removeRow = (row: Row) => {
    unchoose(row);
    rows.value = rows.value.filter((other) => other.id !== row.id);
};

const setVersion = (row: Row, value: string | null) => {
    unchoose(row);
    row.ip_version = value === '6' ? 6 : 4;
    row.pool = '';
    row.address = '';
};

const openDialog = () => {
    form.clearErrors();
    removed.value = [];
    rows.value = [];
    primary.value = { 4: currentPrimary(4), 6: currentPrimary(6) };
    open.value = true;
};

const changed = computed(
    () =>
        removed.value.length > 0 ||
        rows.value.length > 0 ||
        VERSIONS.some(
            (version) => primary.value[version] !== currentPrimary(version),
        ),
);

const submit = () => {
    if (!changed.value) {
        open.value = false;

        return;
    }

    const primaryFor = (version: Version) => {
        const chosen = primary.value[version];

        return chosen !== currentPrimary(version) && !chosen?.startsWith(ROW)
            ? chosen
            : null;
    };

    form.add = rows.value.map((row) => ({
        ip_version: row.ip_version,
        pool: row.pool === '' ? null : row.pool,
        address: row.specific ? row.address.trim() || null : null,
        primary: primary.value[row.ip_version] === `${ROW}${row.id}`,
    }));
    form.remove = [...removed.value];
    form.primary_ipv4 = primaryFor(4);
    form.primary_ipv6 = primaryFor(6);

    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
};

// The server reports rule failures under "addresses" and rows as "add.<n>.…".
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const rowError = (index: number) =>
    errors.value[`add.${index}.address`] ?? errors.value[`add.${index}.pool`];
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        :disabled="disabled || addresses.length === 0"
        @click="openDialog"
    >
        <Network class="h-4 w-4" />
        {{ $t('Edit IP addresses') }}
    </Button>

    <FormDialog
        v-model:open="open"
        title="Edit IP addresses"
        description="Added addresses come from pools on the server's network. Changes are applied inside the server through the guest agent, without a restart."
        submit-label="Update"
        :processing="form.processing"
        @submit="submit"
    >
        <div class="grid gap-2" data-test="current-addresses">
            <p class="text-sm font-medium">{{ $t('Current addresses') }}</p>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="address in addresses"
                    :key="address.address"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                    :class="{
                        'opacity-60': removed.includes(address.address),
                    }"
                >
                    <span class="flex items-center gap-2">
                        <span
                            class="font-mono"
                            :class="{
                                'line-through': removed.includes(
                                    address.address,
                                ),
                            }"
                            >{{ address.address }}</span
                        >
                        <span class="text-xs text-muted-foreground"
                            >({{ address.pool.name }})</span
                        >
                    </span>
                    <label class="flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            class="h-4 w-4 accent-destructive"
                            :checked="removed.includes(address.address)"
                            :data-test="`remove-${address.address}`"
                            @change="toggleRemove(address.address)"
                        />
                        {{ $t('Remove') }}
                    </label>
                </li>
            </ul>
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-medium">{{ $t('Add addresses') }}</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="pools.length === 0"
                    data-test="add-row"
                    @click="addRow"
                >
                    <ListPlus class="h-4 w-4" />
                    {{ $t('Add address') }}
                </Button>
            </div>
            <p v-if="pools.length === 0" class="text-sm text-muted-foreground">
                {{
                    $t(
                        'No active pool of this location, network model, bridge and VLAN.',
                    )
                }}
            </p>
            <div
                v-for="(row, index) in rows"
                :key="row.id"
                class="grid gap-2 rounded-md border p-3"
                :data-test="`add-row-${index}`"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <NativeSelect
                        v-if="versionOptions.length > 1"
                        :model-value="String(row.ip_version)"
                        :options="versionOptions"
                        class="w-24"
                        :aria-label="$t('IP version')"
                        @update:model-value="setVersion(row, $event)"
                    />
                    <NativeSelect
                        v-model="row.pool"
                        :options="poolOptions(row.ip_version)"
                        class="min-w-0 flex-1"
                        :aria-label="$t('Pool')"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('Remove row')"
                        :title="$t('Remove row')"
                        @click="removeRow(row)"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <NativeSelect
                        :model-value="row.specific ? 'specific' : 'next'"
                        :options="[
                            {
                                value: 'next',
                                label: $t('Next free address'),
                            },
                            {
                                value: 'specific',
                                label: $t('A specific free address…'),
                            },
                        ]"
                        class="w-56"
                        :aria-label="$t('Address')"
                        @update:model-value="
                            row.specific = $event === 'specific'
                        "
                    />
                    <template v-if="row.specific">
                        <Input
                            v-model="row.address"
                            class="min-w-0 flex-1 font-mono"
                            autocomplete="off"
                            :list="`free-addresses-${index}`"
                            :placeholder="
                                row.ip_version === 4
                                    ? '203.0.113.10'
                                    : '2001:db8::10'
                            "
                            required
                        />
                        <datalist :id="`free-addresses-${index}`">
                            <option
                                v-for="free in freeAddresses(row)"
                                :key="free"
                                :value="free"
                            />
                        </datalist>
                    </template>
                </div>
                <p v-if="rowError(index)" class="text-sm text-destructive">
                    {{ rowError(index) }}
                </p>
            </div>
        </div>

        <div v-if="versionsShown.length > 0" class="grid gap-2">
            <p class="text-sm font-medium">{{ $t('Primary address') }}</p>
            <div
                v-for="version in versionsShown"
                :key="version"
                class="flex flex-wrap items-center gap-2"
            >
                <label
                    :for="`primary_ipv${version}`"
                    class="w-12 text-sm text-muted-foreground"
                    >IPv{{ version }}</label
                >
                <NativeSelect
                    :id="`primary_ipv${version}`"
                    v-model="primary[version]"
                    :options="primaryOptions(version)"
                    :placeholder="
                        version === 6 && currentPrimary(6) === null
                            ? $t('The first added address')
                            : $t('Choose…')
                    "
                    class="min-w-0 flex-1"
                />
                <p
                    v-if="
                        currentPrimary(version) !== null &&
                        missingPrimary(version)
                    "
                    class="w-full text-sm text-destructive"
                >
                    {{
                        $t(
                            'Choose a new primary address before removing :address.',
                            { address: currentPrimary(version) ?? '' },
                        )
                    }}
                </p>
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'An added address that becomes the primary replaces the old one when you remove it; otherwise the old one stays as an additional address.',
                    )
                }}
            </p>
        </div>

        <p
            v-if="removed.length > 0"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            data-test="release-warning"
        >
            {{
                $t(
                    'Released back to their pools, with their reverse DNS records removed: :addresses',
                    { addresses: removed.join(', ') },
                )
            }}
        </p>

        <p v-if="errors.addresses" class="text-sm text-destructive">
            {{ errors.addresses }}
        </p>
    </FormDialog>
</template>
