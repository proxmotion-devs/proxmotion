<script setup lang="ts">
import FormField from '@/components/admin/FormField.vue';
import { Input } from '@/components/ui/input';
import type { OptionalNumber } from '@/lib/forms';

/**
 * CPU and disk limits of a package or server (docs/adr/0034); empty is
 * unlimited. Used by the package form, the custom create form and the
 * Change resources dialog.
 */
defineProps<{
    errors: Partial<Record<string, string>>;
}>();

const cpuLimit = defineModel<OptionalNumber>('cpuLimit', { required: true });
const cpuUnits = defineModel<OptionalNumber>('cpuUnits', { required: true });
const diskMbS = defineModel<OptionalNumber>('diskMbS', { required: true });
const diskIops = defineModel<OptionalNumber>('diskIops', { required: true });

const optional = (value: string | number): OptionalNumber =>
    value === '' ? '' : Number(value);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <FormField
            label="CPU limit (cores)"
            for="cpu_limit"
            :error="errors.cpu_limit"
            hint="At most this much CPU time, e.g. 1.5, even with more vCPUs. Leave empty for unlimited."
        >
            <Input
                id="cpu_limit"
                :model-value="cpuLimit"
                type="number"
                min="0.1"
                step="0.1"
                @update:model-value="(value) => (cpuLimit = optional(value))"
            />
        </FormField>
        <FormField
            label="CPU weight"
            for="cpu_units"
            :error="errors.cpu_units"
            hint="Share of CPU time on a busy node; Proxmox's default is 100. Leave empty for the default."
        >
            <Input
                id="cpu_units"
                :model-value="cpuUnits"
                type="number"
                min="1"
                max="10000"
                @update:model-value="(value) => (cpuUnits = optional(value))"
            />
        </FormField>
        <FormField
            label="Disk speed (MB/s)"
            for="disk_mb_s"
            :error="errors.disk_mb_s"
            hint="Reading and writing each. Leave empty for unlimited."
        >
            <Input
                id="disk_mb_s"
                :model-value="diskMbS"
                type="number"
                min="1"
                @update:model-value="(value) => (diskMbS = optional(value))"
            />
        </FormField>
        <FormField
            label="Disk operations (IOPS)"
            for="disk_iops"
            :error="errors.disk_iops"
            hint="Reading and writing each. Leave empty for unlimited."
        >
            <Input
                id="disk_iops"
                :model-value="diskIops"
                type="number"
                min="10"
                @update:model-value="(value) => (diskIops = optional(value))"
            />
        </FormField>
    </div>
</template>
