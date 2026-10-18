<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { formatMib } from '@/lib/format';
import type { Option, PlacementNode } from '@/types/admin';

/**
 * Where a custom server goes: the best node of a placement group
 * (docs/adr/0020) or a single node (and optionally a storage) chosen by the
 * administrator. The panel checks the rest (capacity, bridge, template) when
 * the server is created. Package servers are placed automatically.
 */
const props = defineProps<{
    nodes: PlacementNode[];
    groups: (Option & { location: string })[];
    location: string | null;
    errors: Record<string, string>;
}>();

const placement = defineModel<'group' | 'node' | null>('placement', {
    required: true,
});
const group = defineModel<string | null>('group', { required: true });
const node = defineModel<string | null>('node', { required: true });
const storage = defineModel<string>('storage', { required: true });

const placements = computed(() => [
    { value: 'group' as const, label: trans('Placement group') },
    { value: 'node' as const, label: trans('Single node') },
]);

// Keep the chosen option while it is offered, else take the first one.
const keepOffered = (
    model: { value: string | null },
    options: { value: string }[],
) => {
    if (!options.some((option) => option.value === model.value)) {
        model.value = options[0]?.value ?? null;
    }
};

const groupOptions = computed(() =>
    props.groups
        .filter((candidate) => candidate.location === props.location)
        .map((candidate) => ({
            value: candidate.value,
            label: candidate.label,
        })),
);

watch(groupOptions, (options) => keepOffered(group, options), {
    immediate: true,
});

// Without a group in the location, start with a single node.
if (placement.value === 'group' && groupOptions.value.length === 0) {
    placement.value = 'node';
}

const nodeOptions = computed(() =>
    props.nodes
        .filter((candidate) => candidate.location === props.location)
        .map((candidate) => ({
            value: candidate.value,
            label: !candidate.online
                ? trans(':node — offline', { node: candidate.label })
                : candidate.maintenance
                  ? trans(':node — maintenance', { node: candidate.label })
                  : candidate.label,
        })),
);

watch(nodeOptions, (options) => keepOffered(node, options), {
    immediate: true,
});

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
        hint="A placement group picks its best node and uses its own storages (or those marked “Use for servers”). A single node may also be in maintenance."
    >
        <NativeSelect
            id="placement"
            v-model="placement"
            :options="placements"
        />
    </FormField>

    <FormField
        v-if="placement === 'group'"
        label="Placement group"
        for="placement_group"
        :error="errors.placement_group"
    >
        <NativeSelect
            id="placement_group"
            v-model="group"
            :options="groupOptions"
            placeholder="No placement group in this location"
        />
    </FormField>

    <div v-if="placement === 'node'" class="grid gap-4 sm:grid-cols-2">
        <FormField label="Node" for="node" :error="errors.node">
            <NativeSelect
                id="node"
                v-model="node"
                :options="nodeOptions"
                placeholder="No node in this location"
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
