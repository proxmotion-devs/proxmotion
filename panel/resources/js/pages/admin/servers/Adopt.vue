<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes } from '@/lib/format';
import { adopt, index } from '@/routes/admin/servers';
import { store as storeAdoption } from '@/routes/admin/servers/adopt';
import { NETWORK_MODELS } from '@/types/admin';
import type { NetworkModel, Option } from '@/types/admin';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Servers', href: index() },
            { title: 'Adopt existing VM', href: adopt() },
        ],
    },
});

type Candidate = {
    vmid: number;
    name: string | null;
    node: string;
    status: string | null;
    cpus: number | null;
    memory_bytes: number | null;
    disk_bytes: number | null;
};

const props = defineProps<{
    clusters: (Option & { resource_pool: string | null })[];
    cluster: string | null;
    candidates: Candidate[];
    error: string | null;
    users: Option[];
    packages: Option[];
    addresses: Option<number>[];
}>();

const selectedCluster = ref<string | null>(props.cluster);
const pool = computed(
    () =>
        props.clusters.find((c) => c.value === props.cluster)?.resource_pool ??
        null,
);

const chooseCluster = (uuid: string | null) => {
    router.visit(adopt({ query: uuid ? { cluster: uuid } : {} }));
};

// "" = no package: the server keeps the VM's own resources.
const packageOptions = computed(() => [
    { value: '', label: trans('No package (custom)') },
    ...props.packages,
]);

const modelOptions = NETWORK_MODELS.map((model) => ({
    value: model.value,
    label: trans(model.label),
}));

const dialogOpen = ref(false);
const candidate = ref<Candidate | null>(null);
const addressFilter = ref('');
const form = useForm({
    cluster: props.cluster,
    vmid: 0,
    user: props.users[0]?.value ?? null,
    package: '' as string | null,
    network_model: 'bridged' as NetworkModel | null,
    addresses: [] as number[],
});

const filteredAddresses = computed(() =>
    props.addresses
        .filter((address) => address.label.includes(addressFilter.value.trim()))
        .slice(0, 50),
);

const openAdopt = (vm: Candidate) => {
    candidate.value = vm;
    form.clearErrors();
    form.cluster = props.cluster;
    form.vmid = vm.vmid;
    form.addresses = [];
    addressFilter.value = '';
    dialogOpen.value = true;
};

const toggleAddress = (id: number, value: boolean | 'indeterminate') => {
    form.addresses =
        value === true
            ? [...form.addresses, id]
            : form.addresses.filter((existing) => existing !== id);
};

const submit = () => {
    form.submit(storeAdoption(), {
        onSuccess: () => (dialogOpen.value = false),
    });
};
</script>

<template>
    <Head title="Adopt existing VM" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Adopt existing VM"
            description="Assign a VM that already runs on Proxmox to a customer. Nothing is changed on Proxmox: the VM is not restarted or reconfigured."
        />

        <div class="max-w-sm">
            <FormField label="Cluster" for="cluster">
                <NativeSelect
                    id="cluster"
                    v-model="selectedCluster"
                    :options="clusters"
                    placeholder="Choose a cluster"
                    @update:model-value="chooseCluster"
                />
            </FormField>
        </div>

        <p v-if="pool" class="text-sm text-muted-foreground">
            {{
                $t(
                    'Only VMs in the resource pool ":pool" are shown. Move a VM into this pool on Proxmox to adopt it.',
                    { pool },
                )
            }}
        </p>

        <p
            v-if="error"
            class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
        >
            {{ error }}
        </p>

        <div v-if="cluster && !error" class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">VMID</th>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('Node') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Resources') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="vm in candidates"
                        :key="vm.vmid"
                        class="border-t"
                    >
                        <td class="px-4 py-3 font-mono">{{ vm.vmid }}</td>
                        <td class="px-4 py-3">{{ vm.name ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ vm.node }}
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ vm.cpus ?? '?' }} vCPU ·
                            {{ formatBytes(vm.memory_bytes) }} ·
                            {{ formatBytes(vm.disk_bytes) }}
                        </td>
                        <td class="px-4 py-3">{{ vm.status ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="openAdopt(vm)"
                                >{{ $t('Adopt') }}</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="candidates.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ $t('No unmanaged VMs found.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        :title="
            candidate
                ? `VM ${candidate.vmid} · ${candidate.name ?? ''}`
                : 'Adopt'
        "
        submit-label="Adopt"
        :processing="form.processing"
        @submit="submit"
    >
        <p
            v-if="form.errors.vmid"
            class="text-sm text-red-600 dark:text-red-500"
        >
            {{ form.errors.vmid }}
        </p>
        <FormField label="Customer" for="adopt_user" :error="form.errors.user">
            <NativeSelect
                id="adopt_user"
                v-model="form.user"
                :options="users"
                placeholder="Choose a customer"
            />
        </FormField>
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="Package"
                for="adopt_package"
                :error="form.errors.package"
            >
                <NativeSelect
                    id="adopt_package"
                    v-model="form.package"
                    :options="packageOptions"
                />
            </FormField>
            <FormField
                label="Network model"
                for="adopt_model"
                :error="form.errors.network_model"
            >
                <NativeSelect
                    id="adopt_model"
                    v-model="form.network_model"
                    :options="modelOptions"
                />
            </FormField>
        </div>
        <div class="grid gap-2">
            <Label>{{ $t('IP addresses the VM already uses') }}</Label>
            <Input
                v-model="addressFilter"
                class="font-mono"
                :placeholder="$t('Filter')"
            />
            <div class="max-h-48 overflow-y-auto rounded-md border p-2">
                <Label
                    v-for="address in filteredAddresses"
                    :key="address.value"
                    class="flex items-center gap-2 py-1 font-mono text-xs font-normal"
                >
                    <Checkbox
                        :model-value="form.addresses.includes(address.value)"
                        @update:model-value="
                            (value) => toggleAddress(address.value, value)
                        "
                    />
                    {{ address.label }}
                </Label>
                <p
                    v-if="filteredAddresses.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    {{ $t('No free addresses in this location.') }}
                </p>
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(':count selected', {
                        count: String(form.addresses.length),
                    })
                }}
            </p>
        </div>
    </FormDialog>
</template>
