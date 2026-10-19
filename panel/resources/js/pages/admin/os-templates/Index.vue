<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FileCode, Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import GuideBox from '@/components/admin/GuideBox.vue';
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
import type { OsTemplate, ProxmoxTemplate } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'OS templates', href: index() }] },
});

/** Proxmox templates no OS template uses yet, one entry per name. */
type FoundTemplate = {
    name: string;
    proxmox_template_ids: number[];
    places: string[];
    guess: {
        name: string;
        family: string;
        version: string;
        distribution: string | null;
        distribution_version: string | null;
    };
};

const props = defineProps<{
    templates: OsTemplate[];
    proxmoxTemplates: ProxmoxTemplate[];
    found: FoundTemplate[];
    families: string[];
}>();

const familyOptions = computed(() =>
    props.families.map((family) => ({ value: family, label: family })),
);

// The same image usually has the same name on every node and cluster, so
// templates are picked by name: one tick maps all of them.
const templatesByName = computed(() => {
    const groups = new Map<string, ProxmoxTemplate[]>();

    for (const template of props.proxmoxTemplates) {
        const name = template.name ?? `#${template.vmid}`;
        groups.set(name, [...(groups.get(name) ?? []), template]);
    }

    return [...groups.entries()].map(([name, templates]) => ({
        name,
        templates,
        ids: templates.map((template) => template.id),
    }));
});

const mappedLabel = (template: OsTemplate) =>
    props.proxmoxTemplates
        .filter((candidate) =>
            template.proxmox_template_ids.includes(candidate.id),
        )
        .map((candidate) => `${candidate.cluster} #${candidate.vmid}`)
        .join(', ');

/** "pardus 23 · debian 12" for a derivative, "debian 12" otherwise. */
const systemLabel = (template: OsTemplate) => {
    const base = `${template.family} ${template.version}`;

    return template.distribution
        ? `${template.distribution} ${template.distribution_version ?? ''}`.trim() +
              ` · ${base}`
        : base;
};

const dialogOpen = ref(false);
const editing = ref<OsTemplate | null>(null);
// A derivative (Pardus on Debian) names its own system and, in family and
// version, the one it is based on, which apps are matched against (docs/adr/0022).
const derivative = ref(false);

const form = useForm({
    name: '',
    family: 'ubuntu' as string | null,
    version: '',
    distribution: '',
    distribution_version: '',
    is_active: true,
    sort_order: 0,
    proxmox_template_ids: [] as number[],
});

const openForm = (
    template: OsTemplate | null,
    found: FoundTemplate | null = null,
) => {
    editing.value = template;
    form.clearErrors();
    form.defaults({
        name: template?.name ?? found?.guess.name ?? '',
        family: template?.family ?? found?.guess.family ?? 'ubuntu',
        version: template?.version ?? found?.guess.version ?? '',
        distribution:
            (template ? template.distribution : found?.guess.distribution) ??
            '',
        distribution_version:
            (template
                ? template.distribution_version
                : found?.guess.distribution_version) ?? '',
        is_active: template?.is_active ?? true,
        sort_order: template?.sort_order ?? 0,
        proxmox_template_ids: [
            ...(template?.proxmox_template_ids ??
                found?.proxmox_template_ids ??
                []),
        ],
    });
    form.reset();
    derivative.value = form.distribution !== '';
    dialogOpen.value = true;
};

const groupState = (ids: number[]): boolean | 'indeterminate' => {
    const picked = ids.filter((id) => form.proxmox_template_ids.includes(id));

    if (picked.length === 0) {
        return false;
    }

    return picked.length === ids.length ? true : 'indeterminate';
};

const toggleGroup = (ids: number[], value: boolean | 'indeterminate') => {
    const others = form.proxmox_template_ids.filter((id) => !ids.includes(id));
    form.proxmox_template_ids = value === true ? [...others, ...ids] : others;
};

const submit = () => {
    form.transform((data) =>
        derivative.value && data.family !== 'windows'
            ? data
            : { ...data, distribution: '', distribution_version: '' },
    );
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

        <GuideBox
            id="os-templates"
            title="How to add an OS template"
            topic="template-guide"
        >
            <ol>
                <li>
                    {{
                        $t(
                            'In Proxmox, download the installer ISO: node, storage, ISO Images, Download from URL.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Create a VM with QEMU Agent ticked and install a minimal system: one root partition, no swap, no LVM.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Install cloud-init (Windows: Cloudbase-Init) and the guest agent, add a CloudInit drive and convert the VM to a template. The guide in Help has every step for Linux and Windows.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            "Put it in the panel's resource pool and sync the cluster.",
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Templates nothing uses yet appear under Found on Proxmox with a suggested name: click Add. Or use Add OS template and tick the Proxmox templates.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Check the family and version: apps use them to decide whether they fit a server. For a system based on another one, such as Pardus on Debian, tick Based on another system and give the base it was built from.',
                        )
                    }}
                </li>
            </ol>
        </GuideBox>

        <section
            v-if="found.length"
            class="flex flex-col gap-3 rounded-xl border border-dashed p-4"
            data-test="found-templates"
        >
            <div>
                <h3 class="font-medium">{{ $t('Found on Proxmox') }}</h3>
                <p class="text-sm text-muted-foreground">
                    {{
                        $t(
                            'VM templates that are not offered yet. Add one to check the suggested name and version, then save.',
                        )
                    }}
                </p>
            </div>
            <ul class="flex flex-col divide-y">
                <li
                    v-for="item in found"
                    :key="item.name"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ item.name }}
                            <span
                                v-if="item.guess.family !== 'other'"
                                class="font-normal text-muted-foreground"
                                >→ {{ item.guess.name }}</span
                            >
                        </div>
                        <div class="font-mono text-xs text-muted-foreground">
                            {{ item.places.join(', ') }}
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="openForm(null, item)"
                    >
                        <Plus class="h-4 w-4" />
                        {{ $t('Add') }}
                    </Button>
                </li>
            </ul>
        </section>

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
                            {{ systemLabel(template) }}
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
        <Label
            v-if="form.family !== 'windows'"
            class="flex items-start gap-3 font-normal"
        >
            <Checkbox
                v-model="derivative"
                class="mt-0.5"
                data-test="derivative"
            />
            <span class="flex flex-col gap-0.5">
                <span>{{ $t('Based on another system') }}</span>
                <span class="text-xs text-muted-foreground">
                    {{
                        $t(
                            'For a derivative such as Pardus, Linux Mint or Oracle Linux. Apps are matched against the system it is based on.',
                        )
                    }}
                </span>
            </span>
        </Label>
        <div
            v-if="derivative && form.family !== 'windows'"
            class="grid gap-4 sm:grid-cols-2"
        >
            <FormField
                label="Distribution"
                for="distribution"
                :error="form.errors.distribution"
                hint="The ID line of /etc/os-release, e.g. pardus."
            >
                <Input
                    id="distribution"
                    v-model="form.distribution"
                    required
                    placeholder="pardus"
                />
            </FormField>
            <FormField
                label="Its version"
                for="distribution_version"
                :error="form.errors.distribution_version"
            >
                <Input
                    id="distribution_version"
                    v-model="form.distribution_version"
                    placeholder="23.4"
                />
            </FormField>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                :label="derivative ? 'Based on' : 'Family'"
                for="family"
                :error="form.errors.family"
                :hint="
                    derivative
                        ? 'The system it was built from. Choose other when you do not know it: no apps are offered then.'
                        : 'Apps and the default scripts (Linux or Windows) follow the family.'
                "
            >
                <NativeSelect
                    id="family"
                    v-model="form.family"
                    :options="familyOptions"
                />
            </FormField>
            <FormField
                :label="derivative ? 'Base version' : 'Version'"
                for="version"
                :error="form.errors.version"
                :hint="
                    derivative
                        ? 'The version of the base system, e.g. 12 for Pardus 23 on Debian 12.'
                        : undefined
                "
            >
                <Input
                    id="version"
                    v-model="form.version"
                    required
                    :placeholder="derivative ? '12' : '24.04'"
                />
            </FormField>
        </div>

        <div class="grid gap-2">
            <Label>{{ $t('Proxmox templates') }}</Label>
            <p class="text-xs text-muted-foreground">
                {{
                    $t(
                        'Tick the VM template with this system. Templates with the same name on other nodes and clusters are picked with it.',
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
            <div v-else class="flex flex-col divide-y rounded-lg border">
                <Label
                    v-for="group in templatesByName"
                    :key="group.name"
                    class="flex items-start gap-3 p-3 font-normal"
                >
                    <Checkbox
                        class="mt-0.5"
                        :model-value="groupState(group.ids)"
                        @update:model-value="
                            (value) => toggleGroup(group.ids, value)
                        "
                    />
                    <span class="flex flex-col gap-0.5">
                        <span>{{ group.name }}</span>
                        <span class="font-mono text-xs text-muted-foreground">
                            {{
                                group.templates
                                    .map(
                                        (template) =>
                                            `${template.cluster} / ${template.node} #${template.vmid}`,
                                    )
                                    .join(', ')
                            }}
                        </span>
                    </span>
                </Label>
            </div>
            <p
                v-if="form.errors.proxmox_template_ids"
                class="text-sm text-red-600 dark:text-red-500"
            >
                {{ form.errors.proxmox_template_ids }}
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <Label class="flex items-center gap-3 font-normal">
                <Checkbox v-model="form.is_active" />
                {{ $t('Active (offered to customers)') }}
            </Label>
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
    </FormDialog>
</template>
