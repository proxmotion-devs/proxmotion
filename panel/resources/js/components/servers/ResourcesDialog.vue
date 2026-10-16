<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { SlidersHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import type { ResourceOptions, ResourceValues } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Upgrade or downgrade a server: choose a package (its values fill the form)
 * or enter custom resources. The disk only grows.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    options: ResourceOptions;
    disabled?: boolean;
}>();

const open = ref(false);

const fromResources = (resources: ResourceValues) => ({
    cpu_cores: resources.cpu_cores,
    memory_mib: resources.memory_mib,
    disk_gib: resources.disk_gib,
    ipv4_count: resources.ipv4_count,
    ipv6_count: resources.ipv6_count,
    port_speed_mbps: (resources.port_speed_mbps ?? '') as OptionalNumber,
    traffic_gb: (resources.traffic_gb ?? '') as OptionalNumber,
});

const form = useForm({
    package: props.options.current.package as string | null,
    ...fromResources(props.options.current),
    reboot: false,
});

const packageOptions = computed(() => [
    { value: '', label: trans('Custom resources') },
    ...props.options.packages,
]);

// Choosing a package fills in its values; they can still be adjusted.
const choosePackage = (uuid: string | null) => {
    form.package = uuid;
    const chosen = props.options.packages.find((p) => p.value === uuid);

    if (chosen) {
        Object.assign(form, fromResources(chosen));
    }
};

const smallerDisk = computed(
    () => Number(form.disk_gib) < props.options.current.disk_gib,
);

const openDialog = () => {
    form.defaults({
        package: props.options.current.package,
        ...fromResources(props.options.current),
        reboot: false,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
};

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            port_speed_mbps: optionalNumber(data.port_speed_mbps),
            traffic_gb: optionalNumber(data.traffic_gb),
        }))
        .submit(props.action, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                :disabled="disabled"
                @click.prevent="openDialog"
            >
                <SlidersHorizontal class="h-4 w-4" />
                {{ $t('Change resources') }}
            </Button>
        </DialogTrigger>
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DialogTitle>{{ $t('Change resources') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'CPU and memory of a running server take effect when it is restarted. The disk can only grow. With fewer IP addresses, the newest additional ones are released.',
                        )
                    }}
                </DialogDescription>

                <FormField
                    label="Package"
                    for="package"
                    :error="form.errors.package"
                >
                    <NativeSelect
                        id="package"
                        :model-value="form.package"
                        :options="packageOptions"
                        @update:model-value="choosePackage"
                    />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField
                        label="vCPU"
                        for="cpu_cores"
                        :error="form.errors.cpu_cores"
                    >
                        <Input
                            id="cpu_cores"
                            v-model.number="form.cpu_cores"
                            type="number"
                            min="1"
                        />
                    </FormField>
                    <FormField
                        label="Memory (MiB)"
                        for="memory_mib"
                        :error="form.errors.memory_mib"
                    >
                        <Input
                            id="memory_mib"
                            v-model.number="form.memory_mib"
                            type="number"
                            min="128"
                        />
                    </FormField>
                    <FormField
                        label="Disk (GiB)"
                        for="disk_gib"
                        :error="form.errors.disk_gib"
                    >
                        <Input
                            id="disk_gib"
                            v-model.number="form.disk_gib"
                            type="number"
                            min="1"
                        />
                    </FormField>
                    <FormField
                        label="IPv4 addresses"
                        for="ipv4_count"
                        :error="form.errors.ipv4_count"
                    >
                        <Input
                            id="ipv4_count"
                            v-model.number="form.ipv4_count"
                            type="number"
                            min="0"
                        />
                    </FormField>
                    <FormField
                        label="IPv6 addresses"
                        for="ipv6_count"
                        :error="form.errors.ipv6_count"
                    >
                        <Input
                            id="ipv6_count"
                            v-model.number="form.ipv6_count"
                            type="number"
                            min="0"
                        />
                    </FormField>
                    <FormField
                        label="Port speed (Mbps)"
                        for="port_speed_mbps"
                        :error="form.errors.port_speed_mbps"
                        hint="Empty: unlimited."
                    >
                        <Input
                            id="port_speed_mbps"
                            v-model.number="form.port_speed_mbps"
                            type="number"
                            min="1"
                        />
                    </FormField>
                    <FormField
                        label="Traffic (GB)"
                        for="traffic_gb"
                        :error="form.errors.traffic_gb"
                        hint="Empty: unlimited."
                    >
                        <Input
                            id="traffic_gb"
                            v-model.number="form.traffic_gb"
                            type="number"
                            min="1"
                        />
                    </FormField>
                </div>

                <p
                    v-if="smallerDisk"
                    class="text-sm text-amber-700 dark:text-amber-400"
                >
                    {{
                        $t(
                            'Disks cannot shrink: the disk keeps its current size.',
                        )
                    }}
                </p>

                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        :model-value="form.reboot"
                        @update:model-value="
                            (checked) => (form.reboot = checked === true)
                        "
                    />
                    <span>{{
                        $t('Restart the server now if CPU or memory change')
                    }}</span>
                </label>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">{{
                            $t('Cancel')
                        }}</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        {{ $t('Apply') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
