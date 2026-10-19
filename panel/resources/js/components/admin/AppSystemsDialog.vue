<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { systems as saveSystems } from '@/routes/admin/apps';

/** A version an app can be ticked for (AppController::index()). */
export type AppOsVersion = {
    id: number;
    system: string;
    version: string;
    platform: 'linux' | 'windows';
};

/**
 * The operating system versions an app is offered on (docs/adr/0026):
 * administrators tick them; what the recipe says it runs on is marked as
 * suggested.
 */
const props = defineProps<{
    app: {
        slug: string;
        name: string;
        platform: string;
        recipe_os: string[];
        suggested_ids: number[];
        os_version_ids: number[];
    };
    osVersions: AppOsVersion[];
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ os_version_ids: [] as number[] });

watch(
    open,
    (value) => {
        if (value) {
            form.clearErrors();
            form.os_version_ids = [...props.app.os_version_ids];
        }
    },
    { immediate: true },
);

/** The versions grouped by system; another platform than the app's cannot be ticked. */
const groups = computed(() => {
    const list: { system: string; versions: AppOsVersion[] }[] = [];

    for (const version of props.osVersions) {
        const group = list.find((entry) => entry.system === version.system);

        if (group) {
            group.versions.push(version);
        } else {
            list.push({ system: version.system, versions: [version] });
        }
    }

    return list;
});

const fits = (version: AppOsVersion) => version.platform === props.app.platform;

const ticked = (id: number) => form.os_version_ids.includes(id);

const tick = (id: number, value: boolean | 'indeterminate') => {
    const others = form.os_version_ids.filter((entry) => entry !== id);
    form.os_version_ids = value === true ? [...others, id] : others;
};

const tickSuggested = () => {
    form.os_version_ids = [
        ...new Set([...form.os_version_ids, ...props.app.suggested_ids]),
    ];
};

const submit = () =>
    form.put(saveSystems(props.app.slug).url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
</script>

<template>
    <FormDialog
        v-model:open="open"
        title="Operating systems of the app"
        description="The app is offered only on the versions you tick."
        :processing="form.processing"
        @submit="submit"
    >
        <p class="text-sm font-medium">{{ app.name }}</p>
        <p
            v-if="osVersions.length === 0"
            class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
        >
            {{
                $t(
                    'No operating systems yet. Add them on the OS templates page first.',
                )
            }}
        </p>
        <template v-else>
            <div
                v-if="app.recipe_os.length"
                class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/40 p-3 text-xs"
            >
                <span>
                    {{ $t('The recipe says:') }}
                    <span class="font-mono">{{
                        app.recipe_os.join(', ')
                    }}</span>
                </span>
                <Button
                    v-if="app.suggested_ids.length"
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="tick-suggested"
                    @click="tickSuggested"
                >
                    {{ $t('Tick the suggested ones') }}
                </Button>
            </div>
            <div class="flex max-h-80 flex-col gap-3 overflow-y-auto">
                <div v-for="group in groups" :key="group.system">
                    <div class="mb-1 text-sm font-medium">
                        {{ group.system }}
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        <Label
                            v-for="version in group.versions"
                            :key="version.id"
                            class="flex items-center gap-2 font-normal"
                            :class="{ 'opacity-50': !fits(version) }"
                        >
                            <Checkbox
                                :model-value="ticked(version.id)"
                                :disabled="
                                    !fits(version) && !ticked(version.id)
                                "
                                :data-test="`os-version-${version.id}`"
                                @update:model-value="
                                    (value) => tick(version.id, value)
                                "
                            />
                            {{ version.version }}
                            <span
                                v-if="app.suggested_ids.includes(version.id)"
                                class="text-xs text-emerald-600 dark:text-emerald-400"
                                >{{ $t('suggested') }}</span
                            >
                        </Label>
                    </div>
                </div>
            </div>
        </template>
        <p
            v-if="form.errors.os_version_ids"
            class="text-sm text-red-600 dark:text-red-500"
        >
            {{ form.errors.os_version_ids }}
        </p>
    </FormDialog>
</template>
