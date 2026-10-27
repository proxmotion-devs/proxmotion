<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Play, Power, RotateCw, Square } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { PowerAction } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Start, shut down, reboot and stop buttons for an active server, with a
 * confirmation for everything but start.
 */
const props = defineProps<{
    /** Proxmox power state; undefined while loading, null when unknown. */
    power: string | null | undefined;
    /** A task is queued or running for the server. */
    busy: boolean;
    /** Wayfinder route of the power endpoint for an action. */
    action: (action: PowerAction) => RouteDefinition<'post'>;
}>();

// English keys, translated in the template.
const actions: {
    value: PowerAction;
    label: string;
    question: string;
    description: string;
    icon: typeof Play;
    when: 'stopped' | 'running';
}[] = [
    {
        value: 'start',
        label: 'Start',
        question: '',
        description: '',
        icon: Play,
        when: 'stopped',
    },
    {
        value: 'shutdown',
        label: 'Shut down',
        question: 'Shut down the server?',
        description:
            'The operating system is asked to shut down cleanly. If it does not respond within two minutes, the server stays on; use Stop instead.',
        icon: Power,
        when: 'running',
    },
    {
        value: 'reboot',
        label: 'Reboot',
        question: 'Reboot the server?',
        description:
            'The operating system is asked to restart. Running programs are stopped.',
        icon: RotateCw,
        when: 'running',
    },
    {
        value: 'stop',
        label: 'Stop',
        question: 'Stop the server?',
        description:
            'The server is switched off immediately, like pulling the plug. Unsaved data may be lost.',
        icon: Square,
        when: 'running',
    },
];

// Unknown state (also one Proxmox has no name for here): offer everything;
// every action is safe to repeat.
const known = computed(
    () => props.power === 'running' || props.power === 'stopped',
);
const visible = computed(() =>
    actions.filter(
        (item) =>
            !known.value ||
            (item.when === 'running') === (props.power === 'running'),
    ),
);

const confirming = ref<(typeof actions)[number] | null>(null);
const processing = ref(false);

const run = (value: PowerAction) => {
    router.visit(props.action(value), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            confirming.value = null;
        },
    });
};

const choose = (item: (typeof actions)[number]) => {
    if (item.value === 'start') {
        run(item.value);
    } else {
        confirming.value = item;
    }
};

const stateLabel = computed(() => {
    if (props.power === undefined) {
        return 'Checking…';
    }

    if (props.power === 'running') {
        return 'Running';
    }

    return props.power === 'stopped' ? 'Stopped' : 'Unknown';
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <StatusBadge
            :tone="power === 'running' ? 'success' : 'neutral'"
            :label="stateLabel"
        />
        <Button
            v-for="item in visible"
            :key="item.value"
            variant="outline"
            size="sm"
            :disabled="busy || processing"
            @click="choose(item)"
        >
            <component :is="item.icon" class="h-4 w-4" />
            {{ $t(item.label) }}
        </Button>

        <Dialog
            :open="confirming !== null"
            @update:open="(open) => !open && (confirming = null)"
        >
            <DialogContent v-if="confirming">
                <DialogTitle>{{ $t(confirming.question) }}</DialogTitle>
                <DialogDescription>
                    {{ $t(confirming.description) }}
                </DialogDescription>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">{{ $t('Cancel') }}</Button>
                    </DialogClose>
                    <Button
                        :variant="
                            confirming.value === 'stop'
                                ? 'destructive'
                                : 'default'
                        "
                        :disabled="processing"
                        @click="run(confirming.value)"
                    >
                        <Spinner v-if="processing" />
                        {{ $t(confirming.label) }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
