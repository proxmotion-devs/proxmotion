<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Ipv4PoolOption, NetworkModel } from '@/types/admin';

/**
 * The IPv4 addresses of a new server: from the first pool with room, or
 * from a chosen pool, optionally with the addresses ticked by hand (the first
 * ticked is the primary; the others the server needs are the next free ones).
 */
const props = defineProps<{
    pools: Ipv4PoolOption[];
    location: string | null;
    networkModel: NetworkModel | null;
    /** How many IPv4 addresses the server gets. */
    count: number;
    errors: Record<string, string>;
}>();

const pool = defineModel<string>('pool', { required: true });
const addresses = defineModel<string[]>('addresses', { required: true });

const search = ref('');

const usablePools = computed(() =>
    props.pools.filter(
        (candidate) =>
            candidate.location === props.location &&
            candidate.network_model === props.networkModel,
    ),
);

// "" = the first pool of the location with room for the server's addresses.
const poolOptions = computed(() => [
    { value: '', label: trans('Automatic (first pool with room)') },
    ...usablePools.value.map((candidate) => ({
        value: candidate.value,
        disabled: candidate.free < props.count,
        label: trans(':pool — :free free', {
            pool: candidate.label,
            free: String(candidate.free),
        }),
    })),
]);

const chosenPool = computed(() =>
    usablePools.value.find((candidate) => candidate.value === pool.value),
);

watch(
    poolOptions,
    (options) => {
        const current = options.find((option) => option.value === pool.value);

        if (
            current === undefined ||
            ('disabled' in current && current.disabled)
        ) {
            pool.value = '';
        }
    },
    { immediate: true },
);

// Addresses belong to the chosen pool and never exceed what the server gets.
watch(
    () => [pool.value, props.count] as const,
    ([, count]) => {
        const free = chosenPool.value?.addresses ?? [];
        const kept = addresses.value
            .filter((address) => free.includes(address))
            .slice(0, Math.max(count, 0));

        if (kept.length !== addresses.value.length) {
            addresses.value = kept;
        }
    },
);

const shown = computed(() => {
    const term = search.value.trim();
    const free = chosenPool.value?.addresses ?? [];

    return term === ''
        ? free
        : free.filter((address) => address.includes(term));
});

const toggle = (address: string, value: boolean | 'indeterminate') => {
    addresses.value =
        value === true
            ? [...addresses.value, address]
            : addresses.value.filter((chosen) => chosen !== address);
};

const full = computed(() => addresses.value.length >= props.count);

const addressError = computed(
    () =>
        props.errors.ipv4_addresses ??
        Object.entries(props.errors).find(([key]) =>
            key.startsWith('ipv4_addresses.'),
        )?.[1],
);
</script>

<template>
    <template v-if="count > 0">
        <FormField
            label="IPv4 pool"
            for="ipv4_pool"
            :error="errors.ipv4_pool"
            hint="Pools of the location for the chosen network model. Choose one to pick the addresses yourself."
        >
            <NativeSelect
                id="ipv4_pool"
                v-model="pool"
                :options="poolOptions"
            />
        </FormField>

        <div v-if="chosenPool" class="grid gap-2" data-test="ipv4-addresses">
            <Label for="ipv4_search">
                {{
                    $t('IPv4 addresses (:chosen of :count chosen)', {
                        chosen: String(addresses.length),
                        count: String(count),
                    })
                }}
            </Label>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'Optional. The first address you tick is the primary one; the server gets the next free addresses of the pool for the rest.',
                    )
                }}
            </p>
            <Input
                id="ipv4_search"
                v-model="search"
                class="font-mono"
                autocomplete="off"
                :placeholder="$t('Filter, e.g. 203.0.113.1')"
            />
            <div
                class="grid max-h-56 grid-cols-2 gap-x-4 gap-y-1 overflow-y-auto rounded-lg border p-3 sm:grid-cols-3"
            >
                <Label
                    v-for="address in shown"
                    :key="address"
                    class="flex items-center gap-2 font-mono font-normal"
                >
                    <Checkbox
                        :model-value="addresses.includes(address)"
                        :disabled="full && !addresses.includes(address)"
                        @update:model-value="(value) => toggle(address, value)"
                    />
                    {{ address }}
                    <span
                        v-if="addresses[0] === address"
                        class="font-sans text-xs text-muted-foreground"
                        >{{ $t('primary') }}</span
                    >
                </Label>
                <span
                    v-if="shown.length === 0"
                    class="col-span-full text-sm text-muted-foreground"
                >
                    {{ $t('No free address matches.') }}
                </span>
            </div>
            <p
                v-if="chosenPool.free > chosenPool.addresses.length"
                class="text-xs text-muted-foreground"
            >
                {{
                    $t(
                        'Only the first :shown of :free free addresses are listed.',
                        {
                            shown: String(chosenPool.addresses.length),
                            free: String(chosenPool.free),
                        },
                    )
                }}
            </p>
            <InputError :message="addressError" />
        </div>
    </template>
</template>
