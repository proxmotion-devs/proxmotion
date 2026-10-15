<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store, update } from '@/routes/admin/clusters';
import type { Cluster, LocationOption } from '@/types/admin';

const props = defineProps<{
    cluster: Cluster | null;
    locations: LocationOption[];
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    location: null as string | null,
    name: '',
    hosts: '',
    port: 8006,
    token_id: '',
    token_secret: '',
    verify_tls: true,
    resource_pool: 'proxmotion',
    is_active: true,
});

const locationOptions = computed(() =>
    props.locations.map((location) => ({
        value: location.uuid,
        label: location.name,
    })),
);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const cluster = props.cluster;

    form.clearErrors();
    form.defaults({
        location: cluster?.location.uuid ?? props.locations[0]?.uuid ?? null,
        name: cluster?.name ?? '',
        hosts: cluster?.hosts.join('\n') ?? '',
        port: cluster?.port ?? 8006,
        token_id: cluster?.token_id ?? '',
        token_secret: '',
        verify_tls: cluster?.verify_tls ?? true,
        resource_pool: cluster ? (cluster.resource_pool ?? '') : 'proxmotion',
        is_active: cluster?.is_active ?? true,
    });
    form.reset();
});

const hostErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'hosts' || key.startsWith('hosts.'))
        .map(([, message]) => message)
        .join(' '),
);

const submit = () => {
    form.transform((data) => ({
        ...data,
        hosts: data.hosts
            .split(/[\s,]+/)
            .map((host) => host.trim())
            .filter(Boolean),
    })).submit(props.cluster ? update(props.cluster.uuid) : store(), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
};
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="cluster ? 'Edit cluster' : 'Add cluster'"
        description="Connect a Proxmox cluster or a standalone node with an API token. The connection is tested before saving."
        :processing="form.processing"
        @submit="submit"
    >
        <FormField
            label="Location"
            for="location"
            :error="form.errors.location"
        >
            <NativeSelect
                id="location"
                v-model="form.location"
                :options="locationOptions"
                placeholder="Choose a location"
            />
        </FormField>
        <FormField label="Name" for="name" :error="form.errors.name">
            <Input
                id="name"
                v-model="form.name"
                required
                placeholder="fra1-cluster"
            />
        </FormField>
        <FormField
            label="API hosts"
            for="hosts"
            :error="hostErrors"
            hint="One hostname or IP per line, without https:// or port. For clusters, list several nodes for failover."
        >
            <textarea
                id="hosts"
                v-model="form.hosts"
                rows="3"
                required
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                placeholder="pve1.example.com"
            />
        </FormField>
        <div class="grid gap-4 sm:grid-cols-3">
            <FormField label="Port" for="port" :error="form.errors.port">
                <Input
                    id="port"
                    v-model.number="form.port"
                    type="number"
                    min="1"
                    max="65535"
                    required
                />
            </FormField>
            <div class="sm:col-span-2">
                <FormField
                    label="API token ID"
                    for="token_id"
                    :error="form.errors.token_id"
                >
                    <Input
                        id="token_id"
                        v-model="form.token_id"
                        required
                        autocomplete="off"
                        placeholder="proxmotion@pve!panel"
                    />
                </FormField>
            </div>
        </div>
        <FormField
            label="API token secret"
            for="token_secret"
            :error="form.errors.token_secret"
            :hint="
                cluster
                    ? 'Leave empty to keep the current secret.'
                    : 'Stored encrypted. It is never shown again.'
            "
        >
            <Input
                id="token_secret"
                v-model="form.token_secret"
                type="password"
                autocomplete="new-password"
                :required="!cluster"
            />
        </FormField>
        <FormField
            label="Resource pool"
            for="resource_pool"
            :error="form.errors.resource_pool"
            hint="New servers are created in this Proxmox pool; the token only needs VM permissions there. Leave empty to use /vms."
        >
            <Input
                id="resource_pool"
                v-model="form.resource_pool"
                class="font-mono"
                placeholder="proxmotion"
            />
        </FormField>
        <div class="flex flex-col gap-3">
            <Label class="flex items-center gap-3 font-normal">
                <Checkbox v-model="form.verify_tls" />
                {{ $t('Verify TLS certificate') }}
            </Label>
            <p
                v-if="!form.verify_tls"
                class="text-xs text-amber-600 dark:text-amber-400"
            >
                {{
                    $t(
                        'Only disable this for self-signed certificates on a trusted network.',
                    )
                }}
            </p>
            <Label class="flex items-center gap-3 font-normal">
                <Checkbox v-model="form.is_active" />
                {{ $t('Active (synced and used for new servers)') }}
            </Label>
        </div>
    </FormDialog>
</template>
