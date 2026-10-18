<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { formatMib } from '@/lib/format';
import { groupFit, nodeProblems, nodeStorages } from '@/lib/serverWizard';
import type { FitProblem, ServerSize } from '@/lib/serverWizard';
import type { PlacementGroupOption, PlacementNode } from '@/types/admin';

/**
 * Whether a server of this size fits where it is placed: the free capacity
 * of a chosen node, or on how many nodes of a placement group it fits. Only a
 * warning; the panel checks again when the server is created.
 */
const props = defineProps<{
    nodes: PlacementNode[];
    groups: PlacementGroupOption[];
    placement: 'group' | 'node' | null;
    group: string | null;
    node: string | null;
    storage: string;
    size: ServerSize;
}>();

const chosenNode = computed(() =>
    props.placement === 'node'
        ? props.nodes.find((candidate) => candidate.value === props.node)
        : undefined,
);

const chosenGroup = computed(() =>
    props.placement === 'group'
        ? props.groups.find((candidate) => candidate.value === props.group)
        : undefined,
);

const storages = computed(() =>
    chosenNode.value ? nodeStorages(chosenNode.value, props.storage) : [],
);

const problems = computed(() =>
    chosenNode.value
        ? nodeProblems(chosenNode.value, props.size, storages.value)
        : [],
);

const problemText: Record<FitProblem, string> = {
    offline: 'The node is not online.',
    cpu: 'Not enough free vCPU cores on the node.',
    memory: 'Not enough free memory on the node.',
    servers: 'The node has no free server slot.',
    disk: 'No storage has room for the disk.',
};

const free = computed(() => {
    const capacity = chosenNode.value?.capacity;

    if (!capacity) {
        return [];
    }

    const unlimited = trans('no limit');
    const disk = Math.max(
        0,
        ...storages.value.map((candidate) => candidate.free_mib),
    );

    return [
        [
            trans('vCPU cores'),
            capacity.cpu_cores === null
                ? unlimited
                : String(Math.floor(capacity.cpu_cores)),
        ],
        [
            trans('Memory'),
            capacity.memory_mib === null
                ? unlimited
                : formatMib(Math.max(0, capacity.memory_mib)),
        ],
        [
            trans('Server slots'),
            capacity.servers === null ? unlimited : String(capacity.servers),
        ],
        [trans('Disk'), formatMib(disk)],
    ];
});

const fit = computed(() =>
    chosenGroup.value
        ? groupFit(chosenGroup.value, props.nodes, props.size)
        : null,
);
</script>

<template>
    <div
        v-if="chosenNode"
        class="grid gap-2 rounded-lg border p-3 text-sm"
        data-test="capacity-node"
    >
        <div class="font-medium">
            {{ $t('Free on :node', { node: chosenNode.label }) }}
        </div>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
            <div v-for="[label, value] in free" :key="label">
                <dt class="text-xs text-muted-foreground">{{ label }}</dt>
                <dd>{{ value }}</dd>
            </div>
        </dl>
        <p
            v-for="problem in problems"
            :key="problem"
            class="flex items-center gap-2 text-amber-700 dark:text-amber-400"
        >
            <TriangleAlert class="h-4 w-4 shrink-0" />
            {{ $t(problemText[problem]) }}
        </p>
    </div>

    <div
        v-else-if="chosenGroup && fit"
        class="rounded-lg border p-3 text-sm"
        :class="
            fit.fitting === 0
                ? 'text-amber-700 dark:text-amber-400'
                : 'text-muted-foreground'
        "
        data-test="capacity-group"
    >
        <p class="flex items-center gap-2">
            <TriangleAlert v-if="fit.fitting === 0" class="h-4 w-4 shrink-0" />
            {{
                $t(':group: this server fits on :fitting of :total nodes.', {
                    group: chosenGroup.label,
                    fitting: String(fit.fitting),
                    total: String(fit.total),
                })
            }}
        </p>
    </div>
</template>
