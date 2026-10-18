<script setup lang="ts">
import FormField from '@/components/admin/FormField.vue';
import { Input } from '@/components/ui/input';
import type { NewServerResources } from '@/types/admin';

/**
 * The resources of a custom server (one without a package), entered by hand.
 */
defineProps<{
    errors: Record<string, string>;
}>();

const values = defineModel<NewServerResources>('values', { required: true });

const set = <K extends keyof NewServerResources>(
    key: K,
    value: NewServerResources[K],
) => {
    values.value = { ...values.value, [key]: value };
};

const fields = [
    { key: 'cpu_cores', label: 'vCPU cores', min: 1, step: 1 },
    { key: 'memory_mib', label: 'Memory (MiB)', min: 128, step: 128 },
    { key: 'disk_gib', label: 'Disk (GiB)', min: 1, step: 1 },
    { key: 'ipv4_count', label: 'IPv4 addresses', min: 0, step: 1 },
    { key: 'ipv6_count', label: 'IPv6 addresses', min: 0, step: 1 },
] as const;

const optional = (value: string | number): number | '' =>
    value === '' ? '' : Number(value);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-3">
        <FormField
            v-for="field in fields"
            :key="field.key"
            :label="field.label"
            :for="field.key"
            :error="errors[field.key]"
        >
            <Input
                :id="field.key"
                :model-value="values[field.key]"
                type="number"
                :min="field.min"
                :step="field.step"
                required
                @update:model-value="(value) => set(field.key, Number(value))"
            />
        </FormField>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <FormField
            label="Port speed (Mbps)"
            for="port_speed_mbps"
            :error="errors.port_speed_mbps"
            hint="Leave empty for unlimited."
        >
            <Input
                id="port_speed_mbps"
                :model-value="values.port_speed_mbps"
                type="number"
                min="1"
                @update:model-value="
                    (value) => set('port_speed_mbps', optional(value))
                "
            />
        </FormField>
        <FormField
            label="Monthly traffic (GB)"
            for="traffic_gb"
            :error="errors.traffic_gb"
            hint="Leave empty for unlimited."
        >
            <Input
                id="traffic_gb"
                :model-value="values.traffic_gb"
                type="number"
                min="1"
                @update:model-value="
                    (value) => set('traffic_gb', optional(value))
                "
            />
        </FormField>
    </div>
</template>
