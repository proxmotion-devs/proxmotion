<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    tone: 'success' | 'warning' | 'danger' | 'neutral';
    label: string;
}>();

const classes = computed(
    () =>
        ({
            success:
                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
            warning:
                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            danger: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
            neutral:
                'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
        })[props.tone],
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                classes,
            )
        "
    >
        {{ $t(label) }}
    </span>
</template>
