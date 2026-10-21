<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { hostnameExample } from '@/lib/hostnames';
import { destroy, index, store, update } from '@/routes/admin/locations';
import type { Location } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Locations', href: index() }] },
});

defineProps<{ locations: Location[] }>();

const dialogOpen = ref(false);
const editing = ref<Location | null>(null);

const form = useForm({
    name: '',
    code: '',
    country_code: '',
    description: '',
    hostname_template: '',
    protected_networks: '',
});

const templateExample = computed(() =>
    form.hostname_template.trim() === ''
        ? ''
        : hostnameExample(form.hostname_template, form.code),
);

const openForm = (location: Location | null) => {
    editing.value = location;
    form.clearErrors();
    form.defaults({
        name: location?.name ?? '',
        code: location?.code ?? '',
        country_code: location?.country_code ?? '',
        description: location?.description ?? '',
        hostname_template: location?.hostname_template ?? '',
        protected_networks: location?.protected_networks.join('\n') ?? '',
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
    <Head title="Locations" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Locations"
                description="Data centres or regions. Clusters and IP pools belong to a location."
            />
            <Button @click="openForm(null)">
                <Plus class="h-4 w-4" />
                {{ $t('Add location') }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('Code') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Country') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Clusters') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('IP pools') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="location in locations"
                        :key="location.uuid"
                        class="border-t"
                    >
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ location.name }}</div>
                            <div
                                v-if="location.description"
                                class="text-xs text-muted-foreground"
                            >
                                {{ location.description }}
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ location.code }}
                        </td>
                        <td class="px-4 py-3">
                            {{ location.country_code ?? '—' }}
                        </td>
                        <td class="px-4 py-3">{{ location.clusters_count }}</td>
                        <td class="px-4 py-3">{{ location.ip_pools_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="
                                        $t('Edit :name', {
                                            name: location.name,
                                        })
                                    "
                                    @click="openForm(location)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <ConfirmDelete
                                    :action="destroy(location.uuid)"
                                    :name="location.name"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="locations.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{
                                $t(
                                    'No locations yet. Add your first data centre.',
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
        :title="editing ? 'Edit location' : 'Add location'"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField label="Name" for="name" :error="form.errors.name">
            <Input
                id="name"
                v-model="form.name"
                required
                placeholder="Frankfurt"
            />
        </FormField>
        <FormField
            label="Code"
            for="code"
            :error="form.errors.code"
            hint="Short unique identifier, e.g. fra1. Lowercase letters, digits and dashes."
        >
            <Input id="code" v-model="form.code" required placeholder="fra1" />
        </FormField>
        <FormField
            label="Country code"
            for="country_code"
            :error="form.errors.country_code"
            hint="Two-letter ISO code, e.g. DE."
        >
            <Input
                id="country_code"
                v-model="form.country_code"
                maxlength="2"
                placeholder="DE"
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
            label="Hostname template"
            for="hostname_template"
            :error="form.errors.hostname_template"
            hint="Names servers ordered without a hostname, and becomes the PTR of their primary addresses; their Proxmox VM is named after the address. {ip} is the primary IPv4 address with dashes, {vmid} the VMID, {location} the location code. Empty: a hostname is required."
        >
            <Input
                id="hostname_template"
                v-model="form.hostname_template"
                class="font-mono"
                placeholder="ip-{ip}.example.com"
            />
            <p v-if="templateExample" class="text-xs text-muted-foreground">
                {{ $t('Example: :hostname', { hostname: templateExample }) }}
            </p>
        </FormField>
        <FormField
            label="Protected networks"
            for="protected_networks"
            :error="form.errors.protected_networks"
            hint="Servers of this location cannot open connections to these networks, e.g. a management network or the panel. One address or CIDR per line. The Proxmox nodes are always protected."
        >
            <textarea
                id="protected_networks"
                v-model="form.protected_networks"
                rows="3"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                placeholder="198.51.100.0/24"
            />
        </FormField>
    </FormDialog>
</template>
