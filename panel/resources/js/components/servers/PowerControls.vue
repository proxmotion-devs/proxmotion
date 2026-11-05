<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, Pause, Play, Power, RotateCw, Square } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import type { PowerAction } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Start, shut down, reboot and stop for an active server, with a confirmation
 * for everything but start. Only the usual action is a button (Start when the
 * server is off, Reboot when it runs); Shut down and Stop, rarer and the
 * latter like pulling the plug, sit in the menu beside it. The power state
 * itself is `PowerStateBadge`, on the page's state line.
 */
const props = defineProps<{
    /** Proxmox power state; undefined while loading, null when unknown. */
    power: string | null | undefined;
    /** A task is queued or running for the server. */
    busy: boolean;
    /** Wayfinder route of the power endpoint for an action. */
    action: (action: PowerAction) => RouteDefinition<'post'>;
    /** Named in the confirmation, so it is clear which server is affected. */
    hostname?: string;
    /** Offer Suspend in the menu too: on a phone it is otherwise at the bottom of the page. */
    canSuspend?: boolean;
}>();

const emit = defineEmits<{ suspend: [] }>();

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
// Shown as buttons; the rest of what fits goes into the menu.
const PRIMARY: PowerAction[] = ['start', 'reboot'];

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

const buttons = computed(() =>
    visible.value.filter((item) => PRIMARY.includes(item.value)),
);
const menu = computed(() =>
    visible.value.filter((item) => !PRIMARY.includes(item.value)),
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
</script>

<template>
    <!-- Its buttons join the page's row of actions, so they wrap with it. -->
    <div class="contents">
        <Button
            v-for="item in buttons"
            :key="item.value"
            variant="outline"
            size="sm"
            :disabled="busy || processing"
            @click="choose(item)"
        >
            <component :is="item.icon" class="h-4 w-4" />
            {{ $t(item.label) }}
        </Button>
        <DropdownMenu v-if="menu.length || canSuspend">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="busy || processing"
                    data-test="power-menu"
                >
                    {{ $t('More') }}
                    <ChevronDown class="h-4 w-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    v-for="item in menu"
                    :key="item.value"
                    :variant="item.value === 'stop' ? 'destructive' : 'default'"
                    :data-test="`power-${item.value}`"
                    @select="choose(item)"
                >
                    <component :is="item.icon" class="h-4 w-4" />
                    {{ $t(item.label) }}
                </DropdownMenuItem>
                <template v-if="canSuspend">
                    <DropdownMenuSeparator v-if="menu.length" />
                    <DropdownMenuItem
                        data-test="power-suspend"
                        @select="emit('suspend')"
                    >
                        <Pause class="h-4 w-4" />
                        {{ $t('Suspend…') }}
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>

        <Dialog
            :open="confirming !== null"
            @update:open="(open) => !open && (confirming = null)"
        >
            <DialogContent v-if="confirming">
                <DialogTitle>{{ $t(confirming.question) }}</DialogTitle>
                <p
                    v-if="hostname"
                    class="font-mono text-sm break-all text-muted-foreground"
                    data-test="power-confirm-hostname"
                >
                    {{ hostname }}
                </p>
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
