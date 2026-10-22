<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowRightLeft } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { formatMib } from '@/lib/format';
import type { MoveOptions, ServerMoveKind } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Move a server to another node, cluster or location (docs/adr/0037): a node
 * (optionally its storage) or the least used node of a placement group. Leaving
 * the cluster copies the VM and deletes the old one, so the hostname is typed
 * as confirmation; another location gives the server new IP addresses.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    options: MoveOptions;
    hostname: string;
    disabled?: boolean;
}>();

const open = ref(false);

const form = useForm({
    target: 'node' as 'node' | 'group',
    node: null as string | null,
    placement_group: null as string | null,
    storage: '' as string | null,
    ipv4_pool: '' as string | null,
    routed_confirmed: false,
    confirm_hostname: '',
});

const targetOptions = computed(() => [
    { value: 'node', label: trans('A node') },
    { value: 'group', label: trans('A placement group (least used node)') },
]);

const nodeOptions = computed(() =>
    props.options.nodes.map((node) => ({
        value: node.value,
        label:
            node.refusal !== null
                ? `${node.label} — ${node.refusal}`
                : node.maintenance
                  ? `${node.label} (${trans('maintenance')})`
                  : node.label,
        disabled: node.refusal !== null,
        group: node.location_name,
    })),
);

const groupOptions = computed(() =>
    props.options.placementGroups.map((group) => ({
        value: group.value,
        label: group.label,
        group: group.location_name,
    })),
);

const selectedNode = computed(
    () => props.options.nodes.find((node) => node.value === form.node) ?? null,
);

const targetLocation = computed(() =>
    form.target === 'node'
        ? (selectedNode.value?.location ?? null)
        : (props.options.placementGroups.find(
              (group) => group.value === form.placement_group,
          )?.location ?? null),
);

// A placement group may hold nodes of another cluster: the server then asks for the hostname.
const kind = computed<ServerMoveKind | null>(() => {
    if (targetLocation.value === null) {
        return null;
    }

    if (targetLocation.value !== props.options.location) {
        return 'location';
    }

    if (form.target === 'group') {
        return null;
    }

    return selectedNode.value?.cluster === props.options.cluster
        ? 'node'
        : 'cluster';
});

const needsHostname = computed(
    () =>
        kind.value === 'cluster' ||
        kind.value === 'location' ||
        (form.target === 'group' && form.placement_group !== null),
);

const kindNotes: Record<ServerMoveKind, string> = {
    node: 'To another node of the same cluster: live while the server runs. Its IP addresses and VMID stay.',
    cluster:
        'To another cluster of this location: the VM and its disks are copied, live while the server runs, and the old VM is deleted. Its IP addresses stay; its VMID stays when it is free there. Proxmox calls this experimental.',
    location:
        'To another location: the server is shut down, copied and started there with new IP addresses from that location. Its old addresses and the old VM are deleted. Its hostname stays.',
};

const storageOptions = computed(() => [
    { value: '', label: trans('Automatic') },
    ...(selectedNode.value?.storages ?? []).map((storage) => ({
        value: storage.name,
        label: `${storage.name} (${trans(':size free', { size: formatMib(storage.free_mib) })}${storage.shared ? `, ${trans('shared')}` : ''})`,
    })),
]);

const poolOptions = computed(() => [
    { value: '', label: trans('Automatic') },
    ...props.options.ipv4Pools
        .filter((pool) => pool.location === targetLocation.value)
        .map((pool) => ({
            value: pool.value,
            label: `${pool.label} · ${trans(':count free', { count: String(pool.free) })}`,
        })),
]);

const openDialog = () => {
    form.clearErrors();
    form.reset();
    open.value = true;
};

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            storage: data.target === 'node' ? data.storage || null : null,
            ipv4_pool:
                kind.value === 'location' ? data.ipv4_pool || null : null,
        }))
        .submit(props.action, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        :disabled="disabled || options.refusal !== null"
        :title="options.refusal ?? undefined"
        data-test="server-move"
        @click="openDialog"
    >
        <ArrowRightLeft class="h-4 w-4" />
        {{ $t('Move') }}
    </Button>

    <FormDialog
        v-model:open="open"
        title="Move server"
        description="Moves the server to another node. With moves to other clusters turned on, also to another cluster or location."
        submit-label="Move"
        :processing="form.processing"
        @submit="submit"
    >
        <p
            v-if="errors.move"
            class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
            data-test="move-error"
        >
            {{ errors.move }}
        </p>

        <p
            v-if="!options.cross_cluster"
            class="text-sm text-muted-foreground"
            data-test="cross-cluster-off"
        >
            {{
                $t(
                    'Moves to other clusters and locations are turned off. Administrators can allow them under Panel settings.',
                )
            }}
        </p>

        <FormField label="Move to" for="move_target">
            <NativeSelect
                id="move_target"
                v-model="form.target"
                :options="targetOptions"
            />
        </FormField>

        <template v-if="form.target === 'node'">
            <FormField label="Node" for="move_node" :error="errors.node">
                <NativeSelect
                    id="move_node"
                    v-model="form.node"
                    :options="nodeOptions"
                    placeholder="Choose a node"
                    data-test="move-node"
                />
            </FormField>
            <FormField
                v-if="selectedNode"
                label="Storage"
                for="move_storage"
                hint="A disk on shared storage the node has too stays where it is."
                :error="errors.storage"
            >
                <NativeSelect
                    id="move_storage"
                    v-model="form.storage"
                    :options="storageOptions"
                />
            </FormField>
        </template>

        <FormField
            v-else
            label="Placement group"
            for="move_group"
            :error="errors.placement_group"
        >
            <NativeSelect
                id="move_group"
                v-model="form.placement_group"
                :options="groupOptions"
                placeholder="Choose a placement group"
            />
        </FormField>

        <p
            v-if="kind"
            class="rounded-md border bg-muted/40 p-3 text-sm"
            data-test="move-kind"
        >
            {{ $t(kindNotes[kind]) }}
        </p>

        <FormField
            v-if="kind === 'location'"
            label="IPv4 pool for the new addresses"
            for="move_pool"
            :error="errors.ipv4_pool"
        >
            <NativeSelect
                id="move_pool"
                v-model="form.ipv4_pool"
                :options="poolOptions"
            />
        </FormField>

        <label
            v-if="options.routed"
            class="flex items-start gap-2 text-sm"
            data-test="move-routed"
        >
            <Checkbox
                :model-value="form.routed_confirmed"
                @update:model-value="
                    (checked) => (form.routed_confirmed = checked === true)
                "
            />
            <span>{{
                $t(
                    'The provider routes the addresses of this server to the new node (the panel cannot move a route).',
                )
            }}</span>
        </label>

        <FormField
            v-if="needsHostname"
            label="Type the hostname to confirm"
            for="move_confirm_hostname"
            :error="errors.confirm_hostname"
        >
            <Input
                id="move_confirm_hostname"
                v-model="form.confirm_hostname"
                class="font-mono"
                autocomplete="off"
                :placeholder="hostname"
            />
        </FormField>
    </FormDialog>
</template>
