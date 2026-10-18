<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { formatMib } from '@/lib/format';
import { automaticFit } from '@/lib/serverWizard';
import type {
    PackageOption,
    PlacementGroupOption,
    PlacementNode,
} from '@/types/admin';

/**
 * A server from a package: its resources, shown read-only, and where it
 * goes. Like an order from the billing API it is placed automatically on the
 * best node of the package's placement groups in the location, or of the
 * whole location when the package names none.
 */
const props = defineProps<{
    packages: PackageOption[];
    groups: PlacementGroupOption[];
    nodes: PlacementNode[];
    location: string | null;
    errors: Record<string, string>;
}>();

const packageUuid = defineModel<string>('package', { required: true });

const chosen = computed(() =>
    props.packages.find((pkg) => pkg.value === packageUuid.value),
);

const limit = (value: number | null, unit: string) =>
    value === null ? trans('unlimited') : `${value} ${unit}`;

const values = computed(() => {
    const pkg = chosen.value;

    if (!pkg) {
        return [];
    }

    return [
        [trans('vCPU cores'), String(pkg.cpu_cores)],
        [trans('Memory'), formatMib(pkg.memory_mib)],
        [trans('Disk'), formatMib(pkg.disk_gib * 1024)],
        [trans('IPv4 addresses'), String(pkg.ipv4_count)],
        [trans('IPv6 addresses'), String(pkg.ipv6_count)],
        [trans('Port speed'), limit(pkg.port_speed_mbps, 'Mbps')],
        [trans('Monthly traffic'), limit(pkg.traffic_gb, 'GB')],
    ];
});

// The package's groups in the chosen location; none named means any node.
const groupsHere = computed(() =>
    props.groups.filter(
        (group) =>
            group.location === props.location &&
            (chosen.value?.placement_groups ?? []).includes(group.value),
    ),
);

const fit = computed(() =>
    chosen.value
        ? automaticFit(
              props.location,
              chosen.value.placement_groups,
              props.groups,
              props.nodes,
              {
                  cpu_cores: chosen.value.cpu_cores,
                  memory_mib: chosen.value.memory_mib,
                  disk_mib: chosen.value.disk_gib * 1024,
              },
          )
        : null,
);

const placementText = computed(() => {
    if (!chosen.value) {
        return '';
    }

    if (chosen.value.placement_groups.length === 0) {
        return trans('Placed on the best node of the location.');
    }

    return groupsHere.value.length === 0
        ? trans('None of this package’s placement groups is in this location.')
        : trans('Placed on the best node of :groups.', {
              groups: groupsHere.value.map((group) => group.label).join(', '),
          });
});
</script>

<template>
    <FormField
        label="Package"
        for="package"
        :error="errors.package"
        hint="The server gets exactly the package's resources. Choose Custom on the first step to set them yourself."
    >
        <NativeSelect
            id="package"
            v-model="packageUuid"
            :options="packages"
            placeholder="Choose a package"
        />
    </FormField>

    <template v-if="chosen">
        <dl
            class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg border p-3 text-sm sm:grid-cols-4"
            data-test="package-values"
        >
            <div v-for="[label, value] in values" :key="label">
                <dt class="text-xs text-muted-foreground">{{ label }}</dt>
                <dd>{{ value }}</dd>
            </div>
        </dl>

        <div
            class="grid gap-1 rounded-lg border p-3 text-sm"
            data-test="package-placement"
        >
            <div class="font-medium">{{ $t('Placement') }}</div>
            <p>{{ placementText }}</p>
            <p
                v-if="fit && fit.fitting === 0"
                class="flex items-center gap-2 text-amber-700 dark:text-amber-400"
            >
                <TriangleAlert class="h-4 w-4 shrink-0" />
                {{
                    $t(
                        'This server fits on none of the :total nodes it may go to.',
                        {
                            total: String(fit.total),
                        },
                    )
                }}
            </p>
            <p v-else-if="fit" class="text-muted-foreground">
                {{
                    $t('It fits on :fitting of :total nodes.', {
                        fitting: String(fit.fitting),
                        total: String(fit.total),
                    })
                }}
            </p>
        </div>
    </template>
</template>
