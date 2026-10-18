<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import type { PackageOption, NewServerResources } from '@/types/admin';

/**
 * The resources of a new server. A package fills every field with its
 * values; all of them stay editable and the server keeps the package link.
 * Without a package everything is entered by hand.
 */
const props = defineProps<{
    packages: PackageOption[];
    errors: Record<string, string>;
}>();

const packageUuid = defineModel<string>('package', { required: true });
const values = defineModel<NewServerResources>('values', { required: true });

const packageOptions = computed(() => [
    { value: '', label: trans('No package (enter everything)') },
    ...props.packages,
]);

const chosen = computed(() =>
    props.packages.find((pkg) => pkg.value === packageUuid.value),
);

const fromPackage = (pkg: PackageOption): NewServerResources => ({
    cpu_cores: pkg.cpu_cores,
    memory_mib: pkg.memory_mib,
    disk_gib: pkg.disk_gib,
    ipv4_count: pkg.ipv4_count,
    ipv6_count: pkg.ipv6_count,
    port_speed_mbps: pkg.port_speed_mbps ?? '',
    traffic_gb: pkg.traffic_gb ?? '',
});

const choosePackage = (uuid: string | null) => {
    packageUuid.value = uuid ?? '';
    const pkg = props.packages.find((candidate) => candidate.value === uuid);

    if (pkg) {
        values.value = fromPackage(pkg);
    }
};

const set = <K extends keyof NewServerResources>(
    key: K,
    value: NewServerResources[K],
) => {
    values.value = { ...values.value, [key]: value };
};

// "Package: …" under a field whose value differs from the package's.
const changedHint = (
    key: keyof NewServerResources,
    unit = '',
): string | undefined => {
    if (!chosen.value) {
        return undefined;
    }

    const original = fromPackage(chosen.value)[key];

    if (String(original) === String(values.value[key])) {
        return undefined;
    }

    return trans('Package: :value', {
        value: original === '' ? trans('unlimited') : `${original}${unit}`,
    });
};

const limitHint = (key: 'port_speed_mbps' | 'traffic_gb') =>
    changedHint(key) ?? trans('Leave empty for unlimited.');

const fields = [
    { key: 'cpu_cores', label: 'vCPU cores', min: 1, step: 1, unit: '' },
    {
        key: 'memory_mib',
        label: 'Memory (MiB)',
        min: 128,
        step: 128,
        unit: ' MiB',
    },
    { key: 'disk_gib', label: 'Disk (GiB)', min: 1, step: 1, unit: ' GiB' },
    { key: 'ipv4_count', label: 'IPv4 addresses', min: 0, step: 1, unit: '' },
    { key: 'ipv6_count', label: 'IPv6 addresses', min: 0, step: 1, unit: '' },
] as const;

const number = (value: string | number): number => Number(value);
</script>

<template>
    <FormField
        label="Package"
        for="package"
        :error="errors.package"
        hint="Optional. Its values fill the fields below and stay editable; the server keeps the package link."
    >
        <NativeSelect
            id="package"
            :model-value="packageUuid"
            :options="packageOptions"
            @update:model-value="choosePackage"
        />
    </FormField>

    <div class="grid gap-4 sm:grid-cols-3">
        <FormField
            v-for="field in fields"
            :key="field.key"
            :label="field.label"
            :for="field.key"
            :error="errors[field.key]"
            :hint="changedHint(field.key, field.unit)"
        >
            <Input
                :id="field.key"
                :model-value="values[field.key]"
                type="number"
                :min="field.min"
                :step="field.step"
                required
                @update:model-value="(value) => set(field.key, number(value))"
            />
        </FormField>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <FormField
            label="Port speed (Mbps)"
            for="port_speed_mbps"
            :error="errors.port_speed_mbps"
            :hint="limitHint('port_speed_mbps')"
        >
            <Input
                id="port_speed_mbps"
                :model-value="values.port_speed_mbps"
                type="number"
                min="1"
                @update:model-value="
                    (value) =>
                        set(
                            'port_speed_mbps',
                            value === '' ? '' : number(value),
                        )
                "
            />
        </FormField>
        <FormField
            label="Monthly traffic (GB)"
            for="traffic_gb"
            :error="errors.traffic_gb"
            :hint="limitHint('traffic_gb')"
        >
            <Input
                id="traffic_gb"
                :model-value="values.traffic_gb"
                type="number"
                min="1"
                @update:model-value="
                    (value) =>
                        set('traffic_gb', value === '' ? '' : number(value))
                "
            />
        </FormField>
    </div>
</template>
