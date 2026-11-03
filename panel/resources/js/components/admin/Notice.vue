<script setup lang="ts">
import { CircleAlert, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A short note about the state of what the page shows: `warning` for "needs a
 * look", `danger` for something broken. The icon carries the tone as well as
 * the colour (DESIGN.md, Notices).
 */
const props = withDefaults(
    defineProps<{
        tone?: 'warning' | 'danger';
        class?: string;
    }>(),
    { tone: 'warning', class: undefined },
);

const classes = computed(
    () =>
        ({
            warning: 'border-status-warning-border bg-status-warning',
            danger: 'border-destructive/40 bg-status-danger',
        })[props.tone],
);
</script>

<template>
    <div
        :class="
            cn(
                'flex items-start gap-3 rounded-lg border p-3 text-sm break-words',
                classes,
                props.class,
            )
        "
    >
        <component
            :is="tone === 'danger' ? CircleAlert : TriangleAlert"
            class="mt-0.5 size-4 shrink-0"
            :class="
                tone === 'danger'
                    ? 'text-status-danger-text'
                    : 'text-status-warning-text'
            "
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
            <slot />
        </div>
    </div>
</template>
