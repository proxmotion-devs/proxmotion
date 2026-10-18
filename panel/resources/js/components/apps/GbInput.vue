<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';

/**
 * A size entered in GB with common presets, kept in MiB like the recipe
 * format (min_memory_mib, min_disk_mib).
 */
defineProps<{
    id: string;
    presets: number[];
    readonly?: boolean;
}>();

const mib = defineModel<number | undefined>({ required: true });

const gb = computed({
    get: () => (mib.value === undefined ? '' : String(round(mib.value / 1024))),
    set: (value: string | number) => {
        const number = Number(value);
        mib.value =
            value === '' || Number.isNaN(number)
                ? undefined
                : Math.round(number * 1024);
    },
});

function round(value: number): number {
    return Math.round(value * 100) / 100;
}

const label = (preset: number) =>
    preset < 1 ? `${preset * 1024} MB` : `${preset} GB`;

const isCurrent = (preset: number) => mib.value === Math.round(preset * 1024);
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center gap-2">
            <Input
                :id="id"
                v-model="gb"
                type="number"
                min="0.25"
                step="0.25"
                class="w-28"
                :disabled="readonly"
            />
            <span class="text-sm text-muted-foreground">GB</span>
        </div>
        <div class="flex flex-wrap gap-1">
            <button
                v-for="preset in presets"
                :key="preset"
                type="button"
                class="rounded-full border px-2 py-0.5 text-xs transition-colors disabled:cursor-not-allowed"
                :class="
                    isCurrent(preset)
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-muted'
                "
                :disabled="readonly"
                @click="mib = Math.round(preset * 1024)"
            >
                {{ label(preset) }}
            </button>
        </div>
    </div>
</template>
