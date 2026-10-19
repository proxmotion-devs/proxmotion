<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { find } from '@/lib/osTemplates';
import type { Distribution, Family } from '@/lib/osTemplates';
import { destroy, store, update } from '@/routes/admin/distributions';
import {
    destroy as destroyRelease,
    store as storeRelease,
} from '@/routes/admin/distributions/releases';

/**
 * The operating systems the OS template form offers and their versions,
 * managed by administrators (docs/adr/0025).
 */
const props = defineProps<{
    distributions: Distribution[];
    families: Family[];
}>();

const familyLabel = (value: string) =>
    trans(
        props.families.find((family) => family.value === value)?.label ?? value,
    );

const familyOptions = computed(() =>
    props.families.map((family) => ({
        value: family.value,
        label: trans(family.label),
    })),
);

// --- A system ---------------------------------------------------------

const systemOpen = ref(false);
const editing = ref<Distribution | null>(null);

const system = useForm({
    slug: '',
    name: '',
    family: 'debian',
    base: '',
    same_version: false,
});

/** Systems apps can be matched as: of the same family, not built on another one. */
const baseOptions = computed(() => [
    { value: '', label: trans('None') },
    ...props.distributions
        .filter(
            (distribution) =>
                distribution.base === null &&
                distribution.family === system.family &&
                distribution.family !== 'windows' &&
                distribution.id !== editing.value?.id,
        )
        .map((distribution) => ({
            value: distribution.id,
            label: distribution.name,
        })),
]);

const openSystem = (distribution: Distribution | null) => {
    editing.value = distribution;
    system.clearErrors();
    system.defaults({
        slug: distribution?.id ?? '',
        name: distribution?.name ?? '',
        family: distribution?.family ?? 'debian',
        base: distribution?.base ?? '',
        same_version: distribution?.same_version ?? false,
    });
    system.reset();
    systemOpen.value = true;
};

const saveSystem = () => {
    const target = editing.value;

    system
        .transform((data) => {
            const values = { ...data, base: data.base || null };

            if (target) {
                delete (values as Partial<typeof values>).slug;
            }

            return values;
        })
        .submit(target ? update(target.id) : store(), {
            preserveScroll: true,
            onSuccess: () => (systemOpen.value = false),
        });
};

// --- A version --------------------------------------------------------

const releaseOpen = ref(false);
const adding = ref<Distribution | null>(null);

const release = useForm({ version: '', base_version: '' });

const baseOfAdding = computed(() =>
    adding.value?.base && !adding.value.same_version
        ? find(props.distributions, adding.value.base)
        : undefined,
);

const openRelease = (distribution: Distribution) => {
    adding.value = distribution;
    release.clearErrors();
    release.reset();
    releaseOpen.value = true;
};

const saveRelease = () => {
    if (!adding.value) {
        return;
    }

    release.submit(storeRelease(adding.value.id), {
        preserveScroll: true,
        onSuccess: () => (releaseOpen.value = false),
    });
};

const removeRelease = (distribution: Distribution, releaseId: number) =>
    router.visit(
        destroyRelease({ distribution: distribution.id, release: releaseId }),
        { preserveScroll: true },
    );

const shown = (version: string) =>
    version === 'rolling' ? trans('rolling') : version;
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
                            'What the OS template form offers. Add a system or a version of one.',
                        )
                    }}
                </p>
            </div>
            <Button variant="outline" size="sm" @click="openSystem(null)">
                <Plus class="h-4 w-4" />
                {{ $t('Add operating system') }}
            </Button>
        </div>

        <ul class="flex flex-col divide-y">
            <li
                v-for="distribution in distributions"
                :key="distribution.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 py-2"
                :data-test="`os-system-${distribution.id}`"
            >
                <div class="min-w-40">
                    <div class="text-sm font-medium">
                        {{ distribution.name }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ familyLabel(distribution.family) }}
                        <template v-if="distribution.base">
                            ·
                            {{
                                $t('built on :name', {
                                    name:
                                        find(distributions, distribution.base)
                                            ?.name ?? distribution.base,
                                })
                            }}
                        </template>
                    </div>
                </div>
                <div class="flex flex-1 flex-wrap items-center gap-1.5">
                    <span
                        v-for="item in distribution.releases"
                        :key="item.version"
                        class="inline-flex items-center gap-1 rounded-md border bg-muted/40 py-0.5 pr-1 pl-2 text-xs"
                    >
                        {{ shown(item.version) }}
                        <span
                            v-if="item.base_version"
                            class="text-muted-foreground"
                            >→ {{ item.base_version }}</span
                        >
                        <button
                            type="button"
                            class="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                            :aria-label="
                                $t('Delete :name', {
                                    name: `${distribution.name} ${item.version}`,
                                })
                            "
                            @click="removeRelease(distribution, item.id)"
                        >
                            <X class="h-3 w-3" />
                        </button>
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-6 px-2 text-xs"
                        :data-test="`add-version-${distribution.id}`"
                        @click="openRelease(distribution)"
                    >
                        <Plus class="h-3 w-3" />
                        {{ $t('Version') }}
                    </Button>
                </div>
                <div class="flex gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="
                            $t('Edit :name', { name: distribution.name })
                        "
                        @click="openSystem(distribution)"
                    >
                        <Pencil class="h-4 w-4" />
                    </Button>
                    <ConfirmDelete
                        :action="destroy(distribution.id)"
                        :name="distribution.name"
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
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="Name"
                for="system-name"
                :error="system.errors.name"
            >
                <Input
                    id="system-name"
                    v-model="system.name"
                    required
                    placeholder="Zorin OS"
                />
            </FormField>
            <FormField
                label="ID"
                for="system-slug"
                :error="system.errors.slug"
                hint="The ID line of /etc/os-release, e.g. zorin."
            >
                <Input
                    id="system-slug"
                    v-model="system.slug"
                    required
                    :disabled="editing !== null"
                    placeholder="zorin"
                />
            </FormField>
        </div>
        <FormField
            label="Family"
            for="system-family"
            :error="system.errors.family"
            hint="Apps written for a family are offered on all of its systems."
        >
            <NativeSelect
                id="system-family"
                v-model="system.family"
                :options="familyOptions"
            />
        </FormField>
        <FormField
            label="Built on"
            for="system-base"
            :error="system.errors.base"
            hint="Optional: for a system with its own version numbers, such as Pardus on Debian. Apps are then offered as on that system."
        >
            <NativeSelect
                id="system-base"
                v-model="system.base"
                :options="baseOptions"
            />
        </FormField>
        <Label v-if="system.base" class="flex items-center gap-3 font-normal">
            <Checkbox v-model="system.same_version" />
            {{ $t('Its versions are the same (RHEL 9 is AlmaLinux 9)') }}
        </Label>
    </FormDialog>

    <FormDialog
        v-model:open="releaseOpen"
        title="Add version"
        :processing="release.processing"
        @submit="saveRelease"
    >
        <p class="text-sm font-medium">{{ adding?.name }}</p>
        <FormField
            label="Version"
            for="release-version"
            :error="release.errors.version"
            hint="As VERSION_ID in /etc/os-release; it also covers its point releases (12 covers 12.7). Use rolling for a rolling system."
        >
            <Input
                id="release-version"
                v-model="release.version"
                required
                placeholder="24.04"
            />
        </FormField>
        <FormField
            v-if="baseOfAdding"
            label="Matched as"
            hint="The release of the system it is built on that apps are offered as."
            for="release-base"
            :error="release.errors.base_version"
        >
            <NativeSelect
                id="release-base"
                v-model="release.base_version"
                :options="
                    baseOfAdding.releases.map((item) => ({
                        value: item.version,
                        label: `${baseOfAdding?.name} ${item.version}`,
                    }))
                "
            />
        </FormField>
    </FormDialog>
</template>
