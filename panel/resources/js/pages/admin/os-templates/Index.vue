<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FileCode, Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as clustersIndex } from '@/routes/admin/clusters';
import {
    destroy,
    index,
    scripts,
    store,
    update,
} from '@/routes/admin/os-templates';
import type { OsPlatform, OsTemplate, ProxmoxTemplate } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'OS templates', href: index() }] },
});

const props = defineProps<{
    templates: OsTemplate[];
    proxmoxTemplates: ProxmoxTemplate[];
    families: string[];
    capabilities: string[];
    capabilityDefaults: Record<string, string[]>;
}>();

const platformOptions: { value: OsPlatform; label: string }[] = [
    { value: 'linux', label: 'Linux' },
    { value: 'windows', label: 'Windows' },
];

const familyOptions = computed(() =>
    props.families.map((family) => ({ value: family, label: family })),
);

// Proxmox templates grouped by cluster for the mapping checkboxes.
const templatesByCluster = computed(() => {
    const groups = new Map<string, ProxmoxTemplate[]>();

    for (const template of props.proxmoxTemplates) {
        groups.set(template.cluster, [
            ...(groups.get(template.cluster) ?? []),
            template,
        ]);
    }

    return [...groups.entries()];
});

const mappedLabel = (template: OsTemplate) =>
    props.proxmoxTemplates
        .filter((candidate) =>
            template.proxmox_template_ids.includes(candidate.id),
        )
        .map((candidate) => `${candidate.cluster} #${candidate.vmid}`)
        .join(', ');

const dialogOpen = ref(false);
const editing = ref<OsTemplate | null>(null);

const form = useForm({
    name: '',
    family: 'ubuntu' as string | null,
    version: '',
    platform: 'linux' as OsPlatform | null,
    capabilities: [] as string[],
    is_active: true,
    sort_order: 0,
    proxmox_template_ids: [] as number[],
});

const openForm = (template: OsTemplate | null) => {
    editing.value = template;
    form.clearErrors();
    form.defaults({
        name: template?.name ?? '',
        family: template?.family ?? 'ubuntu',
        version: template?.version ?? '',
        platform: template?.platform ?? 'linux',
        capabilities: [
            ...(template?.capabilities ??
                props.capabilityDefaults['ubuntu'] ??
                []),
        ],
        is_active: template?.is_active ?? true,
        sort_order: template?.sort_order ?? 0,
        proxmox_template_ids: [...(template?.proxmox_template_ids ?? [])],
    });
    form.reset();
    dialogOpen.value = true;
};

// The platform picks the default guest scripts and the capabilities what app
// recipes may rely on; both follow the family the admin picks.
const familyChanged = () => {
    form.platform = form.family === 'windows' ? 'windows' : 'linux';
    form.capabilities = [
        ...(props.capabilityDefaults[form.family ?? ''] ?? []),
    ];
};

const toggleCapability = (
    capability: string,
    value: boolean | 'indeterminate',
) => {
    form.capabilities =
        value === true
            ? [...form.capabilities, capability]
            : form.capabilities.filter((existing) => existing !== capability);
};

const toggleMapping = (id: number, value: boolean | 'indeterminate') => {
    form.proxmox_template_ids =
        value === true
            ? [...form.proxmox_template_ids, id]
            : form.proxmox_template_ids.filter((existing) => existing !== id);
};

const submit = () => {
    form.submit(editing.value ? update(editing.value.uuid) : store(), {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
};
</script>

<template>
    <Head title="OS templates" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="OS templates"
                description="Operating systems customers can install. Each one points to a cloud-init VM template on every cluster that offers it."
            />
            <Button @click="openForm(null)">
                <Plus class="h-4 w-4" />
                {{ $t('Add OS template') }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Family') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Proxmox templates') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="template in templates"
                        :key="template.uuid"
                        class="border-t"
                    >
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ template.name }}</span>
                            <StatusBadge
                                v-if="!template.is_active"
                                tone="neutral"
                                label="Inactive"
                                class="ml-2"
                            />
                        </td>
                        <td class="px-4 py-3">
                            {{ template.family }} {{ template.version }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            <span v-if="template.proxmox_template_ids.length">{{
                                mappedLabel(template)
                            }}</span>
                            <StatusBadge
                                v-else
                                tone="warning"
                                label="Not mapped"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    as-child
                                    :aria-label="
                                        $t('Scripts of :name', {
                                            name: template.name,
                                        })
                                    "
                                    :title="$t('Scripts')"
                                >
                                    <Link :href="scripts(template.uuid)">
                                        <FileCode class="h-4 w-4" />
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        $t('Edit :name', {
                                            name: template.name,
                                        })
                                    "
                                    @click="openForm(template)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <ConfirmDelete
                                    :action="destroy(template.uuid)"
                                    :name="template.name"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="templates.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ $t('No OS templates yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        :title="editing ? 'Edit OS template' : 'Add OS template'"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField label="Name" for="name" :error="form.errors.name">
            <Input
                id="name"
                v-model="form.name"
                required
                placeholder="Ubuntu 24.04 LTS"
            />
        </FormField>
        <div class="grid gap-4 sm:grid-cols-4">
            <FormField
                label="Platform"
                for="platform"
                :error="form.errors.platform"
            >
                <NativeSelect
                    id="platform"
                    v-model="form.platform"
                    :options="platformOptions"
                />
            </FormField>
            <FormField label="Family" for="family" :error="form.errors.family">
                <NativeSelect
                    id="family"
                    v-model="form.family"
                    :options="familyOptions"
                    @change="familyChanged"
                />
            </FormField>
            <FormField
                label="Version"
                for="version"
                :error="form.errors.version"
            >
                <Input
                    id="version"
                    v-model="form.version"
                    required
                    placeholder="24.04"
                />
            </FormField>
            <FormField
                label="Sort order"
                for="sort_order"
                :error="form.errors.sort_order"
            >
                <Input
                    id="sort_order"
                    v-model.number="form.sort_order"
                    type="number"
                    min="0"
                />
            </FormField>
        </div>
        <Label class="flex items-center gap-3 font-normal">
            <Checkbox v-model="form.is_active" />
            {{ $t('Active (offered to customers)') }}
        </Label>

        <div class="grid gap-2">
            <Label>{{ $t('Capabilities') }}</Label>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'What the template offers to apps. Apps ask for capabilities instead of naming distributions; the family suggests them.',
                    )
                }}
            </p>
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                <Label
                    v-for="capability in capabilities"
                    :key="capability"
                    class="flex items-center gap-2 font-normal"
                >
                    <Checkbox
                        :model-value="form.capabilities.includes(capability)"
                        @update:model-value="
                            (value) => toggleCapability(capability, value)
                        "
                    />
                    <span class="font-mono text-xs">{{ capability }}</span>
                </Label>
            </div>
            <p
                v-if="form.errors.capabilities"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ form.errors.capabilities }}
            </p>
        </div>

        <div class="grid gap-2">
            <Label>{{ $t('Proxmox templates') }}</Label>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'Pick the matching template on each cluster. With local storage, pick one per node.',
                    )
                }}
            </p>
            <p
                v-if="proxmoxTemplates.length === 0"
                class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
            >
                {{
                    $t(
                        'No VM templates found yet. Create a cloud-init template on Proxmox and sync the cluster.',
                    )
                }}
                <Link :href="clustersIndex()" class="font-medium underline">{{
                    $t('Clusters')
                }}</Link>
            </p>
            <div
                v-for="[cluster, clusterTemplates] in templatesByCluster"
                :key="cluster"
                class="rounded-lg border p-3"
            >
                <div class="mb-2 text-sm font-medium">{{ cluster }}</div>
                <Label
                    v-for="candidate in clusterTemplates"
                    :key="candidate.id"
                    class="flex items-center gap-3 py-1 font-normal"
                >
                    <Checkbox
                        :model-value="
                            form.proxmox_template_ids.includes(candidate.id)
                        "
                        @update:model-value="
                            (value) => toggleMapping(candidate.id, value)
                        "
                    />
                    <span class="font-mono text-xs">#{{ candidate.vmid }}</span>
                    <span>{{ candidate.name ?? '—' }}</span>
                    <span class="text-xs text-muted-foreground"
                        >({{ candidate.node }})</span
                    >
                </Label>
            </div>
            <p
                v-if="form.errors.proxmox_template_ids"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ form.errors.proxmox_template_ids }}
            </p>
        </div>
    </FormDialog>
</template>
