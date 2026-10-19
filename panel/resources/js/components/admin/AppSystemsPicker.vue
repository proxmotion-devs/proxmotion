<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { systems as saveSystems } from '@/routes/admin/apps';

/** A version an app can be ticked for (AppSystems::options()). */
export type AppOsVersion = {
    id: number;
    system: string;
    version: string;
    platform: 'linux' | 'windows';
};

/**
 * The operating system versions an app is offered on (docs/adr/0026). The
 * one place they are chosen: a tick is saved at once, nothing waits for
 * publishing. What the recipe says it runs on is marked as suggested.
 */
const props = defineProps<{
    app: {
        slug: string;
        platform: string;
        suggested_ids: number[];
        os_version_ids: number[];
    };
    osVersions: AppOsVersion[];
}>();

const ticked = ref<number[]>([...props.app.os_version_ids]);
const saving = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.app.os_version_ids,
    (ids) => (ticked.value = [...ids]),
);

/** The versions grouped by system. */
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

/** A Linux app is never offered on Windows, and the other way round. */
const fits = (version: AppOsVersion) => version.platform === props.app.platform;

const untickedSuggestions = computed(() =>
    props.app.suggested_ids.filter((id) => !ticked.value.includes(id)),
);

const save = (ids: number[]) => {
    const before = ticked.value;
    ticked.value = ids;
    saving.value = true;
    error.value = null;

    router.put(
        saveSystems(props.app.slug).url,
        { os_version_ids: ids },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                ticked.value = before;
                error.value = errors.os_version_ids ?? null;
            },
            onFinish: () => (saving.value = false),
        },
    );
};

const tick = (id: number, value: boolean | 'indeterminate') => {
    const others = ticked.value.filter((entry) => entry !== id);
    save(value === true ? [...others, id] : others);
};

const tickSuggested = () =>
    save([...new Set([...ticked.value, ...props.app.suggested_ids])]);
</script>

<template>
    <div class="grid gap-3" data-test="app-systems">
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
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-muted-foreground">
                    {{
                        $t(
                            'The app is offered only on the versions you tick. A tick is saved at once.',
                        )
                    }}
                </p>
                <Button
                    v-if="untickedSuggestions.length"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="saving"
                    data-test="tick-suggested"
                    @click="tickSuggested"
                >
                    {{ $t('Tick the suggested ones') }}
                </Button>
            </div>
            <div class="flex flex-col gap-3">
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
                                :model-value="ticked.includes(version.id)"
                                :disabled="
                                    saving ||
                                    (!fits(version) &&
                                        !ticked.includes(version.id))
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
        <p v-if="error" class="text-sm text-red-600 dark:text-red-500">
            {{ error }}
        </p>
    </div>
</template>
