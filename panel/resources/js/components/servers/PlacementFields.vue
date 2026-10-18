<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { formatMib } from '@/lib/format';
import type { PlacementNode } from '@/types/admin';

/**
 * Where a new server goes: the best node of the location, or a node (and
 * optionally a storage) chosen by the administrator. Nodes outside the
 * package's clusters are shown but cannot be chosen; the panel checks the
 * rest (capacity, bridge, template) when the server is created.
 */
const props = defineProps<{
    nodes: PlacementNode[];
    location: string | null;
    /** The clusters the package allows; empty for any. */
    clusters: string[];
    errors: Record<string, string>;
}>();

const placement = defineModel<'auto' | 'node' | null>('placement', {
    required: true,
});
const node = defineModel<string | null>('node', { required: true });
const storage = defineModel<string>('storage', { required: true });

const placements = computed(() => [
    {
        value: 'auto' as const,
        label: trans('Automatic (best node of the location)'),
    },
    { value: 'node' as const, label: trans('Choose the node and storage') },
]);

const allowed = (candidate: PlacementNode) =>
    props.clusters.length === 0 || props.clusters.includes(candidate.cluster);

const nodeOptions = computed(() =>
    props.nodes
        .filter((candidate) => candidate.location === props.location)
        .map((candidate) => ({
            value: candidate.value,
            disabled: !allowed(candidate),
            label: !allowed(candidate)
                ? trans(':node — not allowed by this package', {
                      node: candidate.label,
                  })
                : !candidate.online
                  ? trans(':node — offline', { node: candidate.label })
                  : candidate.maintenance
                    ? trans(':node — maintenance', { node: candidate.label })
                    : candidate.label,
        })),
);

// Keep the chosen node only while the location and package allow it.
watch(
    nodeOptions,
    (options) => {
        const current = options.find((option) => option.value === node.value);

        if (current === undefined || current.disabled) {
            node.value =
                options.find((option) => !option.disabled)?.value ?? null;
        }
    },
    { immediate: true },
);

// "" = the storage of the node with the most room among those used for servers.
const storageOptions = computed(() => [
    { value: '', label: trans('Automatic (most free space)') },
    ...(
        props.nodes.find((candidate) => candidate.value === node.value)
            ?.storages ?? []
    ).map((option) => ({
        value: option.name,
        label: trans(':storage (:type) — :free free', {
            storage: option.name,
            type: option.shared
                ? `${option.type}, ${trans('shared')}`
                : option.type,
            free: formatMib(option.free_mib),
        }),
    })),
]);

watch(node, () => {
    if (
        !storageOptions.value.some((option) => option.value === storage.value)
    ) {
        storage.value = '';
    }
});
</script>

<template>
    <FormField
        label="Placement"
        for="placement"
        :error="errors.placement"
        hint="Automatic placement uses only storages marked “Use for servers”. A chosen node may also be in maintenance."
    >
        <NativeSelect
            id="placement"
            v-model="placement"
            :options="placements"
        />
    </FormField>

    <div v-if="placement === 'node'" class="grid gap-4 sm:grid-cols-2">
        <FormField label="Node" for="node" :error="errors.node">
            <NativeSelect
                id="node"
                v-model="node"
                :options="nodeOptions"
                placeholder="No node in this location that this package may use"
            />
        </FormField>
        <FormField
            label="Storage"
            for="storage"
            :error="errors.storage"
            hint="Free space after over-commit and the disks already booked."
        >
            <NativeSelect
                id="storage"
                v-model="storage"
                :options="storageOptions"
            />
        </FormField>
    </div>
</template>
