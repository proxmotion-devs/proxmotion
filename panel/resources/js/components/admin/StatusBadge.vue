<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { StatusTone } from '@/types/admin';

const props = defineProps<{
    tone: StatusTone;
    label: string;
}>();

// `progress` is work still running: neutral colours with a spinner, so it is
// not mistaken for a warning (DESIGN.md, Status badges).
const classes = computed(
    () =>
        ({
            success: 'bg-status-success text-status-success-foreground',
            warning: 'bg-status-warning text-status-warning-foreground',
            danger: 'bg-status-danger text-status-danger-foreground',
            neutral: 'bg-status-neutral text-status-neutral-foreground',
            progress: 'bg-status-neutral text-status-neutral-foreground',
        })[props.tone],
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                classes,
            )
        "
    >
        <LoaderCircle
            v-if="tone === 'progress'"
            class="size-3 shrink-0 motion-safe:animate-spin"
            aria-hidden="true"
        />
        {{ $t(label) }}
    </span>
</template>
