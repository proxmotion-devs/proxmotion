<script setup lang="ts" generic="T extends string | number">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineProps<{
    options: { value: T; label: string }[];
    placeholder?: string;
    class?: HTMLAttributes['class'];
}>();

const model = defineModel<T | null>({ required: true });
</script>

<template>
    <select
        v-model="model"
        :class="
            cn(
                'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30',
                $props.class,
            )
        "
    >
        <option v-if="placeholder" :value="null" disabled>
            {{ $t(placeholder) }}
        </option>
        <option
            v-for="option in options"
            :key="option.value"
            :value="option.value"
        >
            {{ option.label }}
        </option>
    </select>
</template>
