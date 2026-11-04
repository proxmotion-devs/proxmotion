<script setup lang="ts">
import { CircleAlert, CircleCheck, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A short note about the state of what the page shows: `warning` for "needs a
 * look", `danger` for something broken, `success` for a check that passed or
 * something created that must be noted now. The icon carries the tone as well
 * as the colour (DESIGN.md, Notices).
 */
const props = withDefaults(
    defineProps<{
        tone?: 'warning' | 'danger' | 'success';
        class?: string;
    }>(),
    { tone: 'warning', class: undefined },
);

const tones = {
    warning: {
        box: 'border-status-warning-border bg-status-warning',
        icon: TriangleAlert,
        iconClass: 'text-status-warning-text',
    },
    danger: {
        box: 'border-destructive/40 bg-status-danger',
        icon: CircleAlert,
        iconClass: 'text-status-danger-text',
    },
    success: {
        box: 'border-status-success-foreground/30 bg-status-success',
        icon: CircleCheck,
        iconClass: 'text-status-success-foreground',
    },
} as const;

const current = computed(() => tones[props.tone]);
</script>

<template>
    <div
        :class="
            cn(
                'flex items-start gap-3 rounded-lg border p-3 text-sm break-words',
                current.box,
                props.class,
            )
        "
    >
        <component
            :is="current.icon"
            class="mt-0.5 size-4 shrink-0"
            :class="current.iconClass"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
            <slot />
        </div>
    </div>
</template>
