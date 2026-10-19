<script setup lang="ts" generic="T extends string | number">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

type Option = { value: T; label: string; disabled?: boolean; group?: string };

const props = defineProps<{
    options: Option[];
    placeholder?: string;
    class?: HTMLAttributes['class'];
}>();

const model = defineModel<T | null>({ required: true });

// Options with a `group` are shown under it (<optgroup>), in the order the groups first appear.
const groups = computed(() => {
    const list: { label: string | undefined; options: Option[] }[] = [];

    for (const option of props.options) {
        const group = list.find((entry) => entry.label === option.group);

        if (group) {
            group.options.push(option);
        } else {
            list.push({ label: option.group, options: [option] });
        }
    }

    return list;
});
</script>

<template>
    <select
        v-model="model"
        :class="
            cn(
                'native-select h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30',
                $props.class,
            )
        "
    >
        <option v-if="placeholder" :value="null" disabled>
            {{ $t(placeholder) }}
        </option>
        <template v-for="group in groups" :key="group.label ?? ''">
            <optgroup v-if="group.label" :label="group.label">
                <option
                    v-for="option in group.options"
                    :key="option.value"
                    :value="option.value"
                    :disabled="option.disabled"
                >
                    {{ option.label }}
                </option>
            </optgroup>
            <template v-else>
                <option
                    v-for="option in group.options"
                    :key="option.value"
                    :value="option.value"
                    :disabled="option.disabled"
                >
                    {{ option.label }}
                </option>
            </template>
        </template>
    </select>
</template>
