<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Pencil, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMib } from '@/lib/format';
import { destroy, index, store, update } from '@/routes/admin/placement-groups';
import type { Option, PlacementGroup } from '@/types/admin';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Placement groups', href: index() }],
    },
});

type GroupNode = {
    id: number;
    name: string;
    cluster: string;
    location: string;
    maintenance: boolean;
    storages: {
        id: number;
        name: string;
        type: string;
        use_for_servers: boolean;
        free_mib: number;
    }[];
};

const props = defineProps<{
    groups: PlacementGroup[];
    locations: Option[];
    nodes: GroupNode[];
}>();

const dialogOpen = ref(false);
const editing = ref<PlacementGroup | null>(null);

const form = useForm({
    name: '',
    description: '',
    location: props.locations[0]?.value ?? null,
    node_ids: [] as number[],
    storage_ids: [] as number[],
});

const locationNodes = computed(() =>
    props.nodes.filter((node) => node.location === form.location),
);

const chosenNodes = computed(() =>
    locationNodes.value.filter((node) => form.node_ids.includes(node.id)),
);

// Nodes and storages of another location (or of nodes no longer chosen) are dropped.
watch(
    () => [form.location, form.node_ids],
    () => {
        const nodeIds = locationNodes.value.map((node) => node.id);
        const nodes = form.node_ids.filter((id) => nodeIds.includes(id));

        if (nodes.length !== form.node_ids.length) {
            form.node_ids = nodes;
        }

        const storageIds = chosenNodes.value.flatMap((node) =>
            node.storages.map((storage) => storage.id),
        );
        const storages = form.storage_ids.filter((id) =>
            storageIds.includes(id),
        );

        if (storages.length !== form.storage_ids.length) {
            form.storage_ids = storages;
        }
    },
);

const toggled = (
    ids: number[],
    id: number,
    value: boolean | 'indeterminate',
) =>
    value === true ? [...ids, id] : ids.filter((existing) => existing !== id);

const nodeNames = (group: PlacementGroup) =>
    props.nodes
        .filter((node) => group.node_ids.includes(node.id))
        .map((node) => node.name)
        .join(', ');

const storageNames = (group: PlacementGroup) =>
    group.storage_ids.length === 0
        ? trans('Storages marked “Use for servers”')
        : props.nodes
              .flatMap((node) =>
                  node.storages
                      .filter((storage) =>
                          group.storage_ids.includes(storage.id),
                      )
                      .map((storage) => `${node.name}/${storage.name}`),
              )
              .join(', ');

const openForm = (group: PlacementGroup | null) => {
    editing.value = group;
    form.clearErrors();
    form.defaults({
        name: group?.name ?? '',
        description: group?.description ?? '',
        location: group?.location.uuid ?? props.locations[0]?.value ?? null,
        node_ids: [...(group?.node_ids ?? [])],
        storage_ids: [...(group?.storage_ids ?? [])],
    });
    form.reset();
    dialogOpen.value = true;
};

const submit = () => {
    const action = editing.value ? update(editing.value.uuid) : store();

    form.submit(action, {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
};
</script>

<template>
    <Head title="Placement groups" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Placement groups"
                description="Named sets of nodes and storages, e.g. “NVMe EPYC”. Packages and new servers can target a group; placement still picks the best node inside it. Nothing changes on Proxmox."
            />
            <Button :disabled="locations.length === 0" @click="openForm(null)">
                <Plus class="h-4 w-4" />
                {{ $t('Add placement group') }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Location') }}
                        </th>
                        <th class="px-4 py-3 font-medium">{{ $t('Nodes') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Storages') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Packages') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="group in groups"
                        :key="group.uuid"
                        class="border-t"
                    >
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ group.name }}</div>
                            <div
                                v-if="group.description"
                                class="text-xs text-muted-foreground"
                            >
                                {{ group.description }}
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ group.location.name }}</td>
                        <td class="px-4 py-3 text-xs">
                            {{ nodeNames(group) || '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ storageNames(group) }}
                        </td>
                        <td class="px-4 py-3">{{ group.packages_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        $t('Edit :name', { name: group.name })
                                    "
                                    @click="openForm(group)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <ConfirmDelete
                                    :action="destroy(group.uuid)"
                                    :name="group.name"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="groups.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{
                                $t(
                                    'No placement groups yet. Without them, servers go to the best node of the location.',
                                )
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        :title="editing ? 'Edit placement group' : 'Add placement group'"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField label="Name" for="name" :error="form.errors.name">
            <Input
                id="name"
                v-model="form.name"
                required
                placeholder="NVMe EPYC"
            />
        </FormField>
        <FormField
            label="Description"
            for="description"
            :error="form.errors.description"
        >
            <Input id="description" v-model="form.description" />
        </FormField>
        <FormField
            label="Location"
            for="location"
            :error="form.errors.location"
        >
            <NativeSelect
                id="location"
                v-model="form.location"
                :options="locations"
            />
        </FormField>

        <div class="grid gap-2">
            <Label>{{ $t('Nodes') }}</Label>
            <div class="flex flex-wrap gap-x-4 gap-y-1 rounded-lg border p-3">
                <Label
                    v-for="node in locationNodes"
                    :key="node.id"
                    class="flex items-center gap-2 font-normal"
                >
                    <Checkbox
                        :model-value="form.node_ids.includes(node.id)"
                        @update:model-value="
                            (value) =>
                                (form.node_ids = toggled(
                                    form.node_ids,
                                    node.id,
                                    value,
                                ))
                        "
                    />
                    {{ node.name }}
                    <span class="text-xs text-muted-foreground"
                        >({{ node.cluster
                        }}<template v-if="node.maintenance"
                            >, {{ $t('maintenance') }}</template
                        >)</span
                    >
                </Label>
                <span
                    v-if="locationNodes.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('This location has no nodes yet.') }}
                </span>
            </div>
            <p
                v-if="form.errors.node_ids"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ form.errors.node_ids }}
            </p>
        </div>

        <div v-if="chosenNodes.length" class="grid gap-2">
            <Label>{{ $t('Storages') }}</Label>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'Tick the storages disks may go to; they need not be marked “Use for servers”. Tick none to use each node’s storages marked “Use for servers”.',
                    )
                }}
            </p>
            <div
                v-for="node in chosenNodes"
                :key="node.id"
                class="rounded-lg border p-3"
            >
                <div class="mb-2 text-sm font-medium">{{ node.name }}</div>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <Label
                        v-for="storage in node.storages"
                        :key="storage.id"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="form.storage_ids.includes(storage.id)"
                            @update:model-value="
                                (value) =>
                                    (form.storage_ids = toggled(
                                        form.storage_ids,
                                        storage.id,
                                        value,
                                    ))
                            "
                        />
                        {{ storage.name }}
                        <span class="text-xs text-muted-foreground">
                            {{
                                $t(':type, :free free', {
                                    type: storage.type,
                                    free: formatMib(storage.free_mib),
                                })
                            }}
                        </span>
                    </Label>
                </div>
            </div>
            <p
                v-if="form.errors.storage_ids"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ form.errors.storage_ids }}
            </p>
        </div>
    </FormDialog>
</template>
