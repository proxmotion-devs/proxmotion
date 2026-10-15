<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
});

const openForm = (location: Location | null) => {
    editing.value = location;
    form.clearErrors();
    form.defaults({
        name: location?.name ?? '',
        code: location?.code ?? '',
        country_code: location?.country_code ?? '',
        description: location?.description ?? '',
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
    </FormDialog>
</template>
