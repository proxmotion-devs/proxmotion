<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { versionLabel } from '@/lib/osTemplates';
import type { OperatingSystem, SystemVersion } from '@/lib/osTemplates';
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

// Deleting a version also takes it off every app ticked for it, and adding
// it back does not tick them again, so it is asked first (with the count).
const removing = ref<{
    system: OperatingSystem;
    version: SystemVersion;
} | null>(null);
const removingOpen = ref(false);

const askRemoveVersion = (entry: OperatingSystem, item: SystemVersion) => {
    removing.value = { system: entry, version: item };
    removingOpen.value = true;
};

const removeDescription = computed(() => {
    if (!removing.value) {
        return '';
    }

    const { system: entry, version: item } = removing.value;
    const name = versionLabel(entry, item.version);

    if (item.apps === 0) {
        return trans(':name is deleted. No OS template or app uses it.', {
            name,
        });
    }

    return item.apps === 1
        ? trans(
              ':name is deleted and 1 app is no longer offered on it. Adding it back does not tick it again.',
              { name },
          )
        : trans(
              ':name is deleted and :count apps are no longer offered on it. Adding it back does not tick them again.',
              { name, count: String(item.apps) },
          );
});

const removeVersion = () => {
    if (removing.value) {
        router.visit(
            destroyVersion({
                operatingSystem: removing.value.system.id,
                version: removing.value.version.id,
            }),
            { preserveScroll: true },
        );
    }
};

/** What deleting a whole system takes off the apps. */
const systemDeleteDescription = (entry: OperatingSystem) =>
    entry.apps === 0
        ? undefined
        : entry.apps === 1
          ? 'Its versions are deleted too, and 1 app is no longer offered on them.'
          : 'Its versions are deleted too, and :count apps are no longer offered on them.';

const inUse = (entry: OperatingSystem) =>
    entry.versions.some((item) => item.templates > 0);
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
                    <template v-if="systems.some(inUse)">
                        {{
                            $t(
                                'Versions an OS template uses cannot be deleted.',
                            )
                        }}
                    </template>
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
                        class="inline-flex h-8 items-stretch overflow-hidden rounded-md border bg-muted/40 text-sm tabular-nums"
                        :data-test="`version-${item.id}`"
                    >
                        <span class="flex items-center px-2.5">{{
                            item.version
                        }}</span>
                        <button
                            v-if="item.templates === 0"
                            type="button"
                            class="flex w-8 items-center justify-center border-l text-muted-foreground outline-none hover:bg-destructive/10 hover:text-destructive focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                            :aria-label="
                                $t('Delete :name', {
                                    name: versionLabel(entry, item.version),
                                })
                            "
                            :title="
                                $t('Delete :name', {
                                    name: versionLabel(entry, item.version),
                                })
                            "
                            :data-test="`remove-version-${item.id}`"
                            @click="askRemoveVersion(entry, item)"
                        >
                            <X class="size-4" />
                        </button>
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        :data-test="`add-version-${entry.id}`"
                        @click="openVersion(entry)"
                    >
                        <Plus class="size-4" />
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
                        :description="systemDeleteDescription(entry)"
                        :description-params="{ count: String(entry.apps) }"
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

    <ConfirmDialog
        v-model:open="removingOpen"
        title="Delete this version?"
        :description="removeDescription"
        confirm-label="Delete version"
        destructive
        @confirm="removeVersion"
    />

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
