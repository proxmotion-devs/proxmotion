<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { OperatingSystem } from '@/lib/osTemplates';
import { destroy, store, update } from '@/routes/admin/operating-systems';
import {
    destroy as destroyVersion,
    store as storeVersion,
} from '@/routes/admin/operating-systems/versions';
import type { OsPlatform } from '@/types/admin';

/**
 * The administrators' operating systems and their versions (docs/adr/0026):
 * OS templates are of one of these versions, and apps are ticked for them.
 */
defineProps<{ systems: OperatingSystem[] }>();

const platformOptions = [
    { value: 'linux', label: 'Linux' },
    { value: 'windows', label: 'Windows' },
];

// --- A system ---------------------------------------------------------

const systemOpen = ref(false);
const editing = ref<OperatingSystem | null>(null);

const system = useForm({ name: '', platform: 'linux' as OsPlatform });

const openSystem = (entry: OperatingSystem | null) => {
    editing.value = entry;
    system.clearErrors();
    system.defaults({
        name: entry?.name ?? '',
        platform: entry?.platform ?? 'linux',
    });
    system.reset();
    systemOpen.value = true;
};

const saveSystem = () =>
    system.submit(editing.value ? update(editing.value.id) : store(), {
        preserveScroll: true,
        onSuccess: () => (systemOpen.value = false),
    });

// --- A version --------------------------------------------------------

const versionOpen = ref(false);
const adding = ref<OperatingSystem | null>(null);

const version = useForm({ version: '' });

const openVersion = (entry: OperatingSystem) => {
    adding.value = entry;
    version.clearErrors();
    version.reset();
    versionOpen.value = true;
};

const saveVersion = () => {
    if (adding.value) {
        version.submit(storeVersion(adding.value.id), {
            preserveScroll: true,
            onSuccess: () => (versionOpen.value = false),
        });
    }
};

const removeVersion = (entry: OperatingSystem, id: number) =>
    router.visit(destroyVersion({ operatingSystem: entry.id, version: id }), {
        preserveScroll: true,
    });
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="os-systems"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="font-medium">{{ $t('Operating systems') }}</h3>
                <p class="text-sm text-muted-foreground">
                    {{
                        $t(
                            'Your list: add a system, then its versions. OS templates and apps use these versions.',
                        )
                    }}
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                data-test="add-system"
                @click="openSystem(null)"
            >
                <Plus class="h-4 w-4" />
                {{ $t('Add operating system') }}
            </Button>
        </div>

        <p
            v-if="systems.length === 0"
            class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
        >
            {{
                $t(
                    'No operating systems yet. Add one, for example Ubuntu, then its versions.',
                )
            }}
        </p>
        <ul v-else class="flex flex-col divide-y">
            <li
                v-for="entry in systems"
                :key="entry.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 py-2"
                :data-test="`os-system-${entry.id}`"
            >
                <div class="min-w-32">
                    <div class="text-sm font-medium">{{ entry.name }}</div>
                    <div class="text-xs text-muted-foreground">
                        {{ entry.platform === 'windows' ? 'Windows' : 'Linux' }}
                    </div>
                </div>
                <div class="flex flex-1 flex-wrap items-center gap-1.5">
                    <span
                        v-for="item in entry.versions"
                        :key="item.id"
                        class="inline-flex items-center gap-1 rounded-md border bg-muted/40 py-0.5 pr-1 pl-2 text-xs"
                    >
                        {{ item.version }}
                        <button
                            v-if="item.templates === 0"
                            type="button"
                            class="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                            :aria-label="
                                $t('Delete :name', {
                                    name: `${entry.name} ${item.version}`,
                                })
                            "
                            @click="removeVersion(entry, item.id)"
                        >
                            <X class="h-3 w-3" />
                        </button>
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-6 px-2 text-xs"
                        :data-test="`add-version-${entry.id}`"
                        @click="openVersion(entry)"
                    >
                        <Plus class="h-3 w-3" />
                        {{ $t('Version') }}
                    </Button>
                </div>
                <div class="flex gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('Edit :name', { name: entry.name })"
                        @click="openSystem(entry)"
                    >
                        <Pencil class="h-4 w-4" />
                    </Button>
                    <ConfirmDelete
                        :action="destroy(entry.id)"
                        :name="entry.name"
                    />
                </div>
            </li>
        </ul>
    </section>

    <FormDialog
        v-model:open="systemOpen"
        :title="editing ? 'Edit operating system' : 'Add operating system'"
        :processing="system.processing"
        @submit="saveSystem"
    >
        <FormField label="Name" for="system-name" :error="system.errors.name">
            <Input
                id="system-name"
                v-model="system.name"
                required
                placeholder="Ubuntu"
            />
        </FormField>
        <FormField
            label="Type"
            for="system-platform"
            :error="system.errors.platform"
            hint="Picks the scripts that set the password, addresses and disk."
        >
            <NativeSelect
                id="system-platform"
                v-model="system.platform"
                :options="platformOptions"
            />
        </FormField>
    </FormDialog>

    <FormDialog
        v-model:open="versionOpen"
        title="Add version"
        :processing="version.processing"
        @submit="saveVersion"
    >
        <p class="text-sm font-medium">{{ adding?.name }}</p>
        <FormField
            label="Version"
            for="system-version"
            :error="version.errors.version"
        >
            <Input
                id="system-version"
                v-model="version.version"
                required
                placeholder="24.04"
            />
        </FormField>
    </FormDialog>
</template>
