<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    allowsNewer,
    expandedFamilies,
    familiesOf,
    selected,
    setNewer,
    toggle,
} from '@/lib/osPicker';
import type { OsGroups, Requires, TemplateVersion } from '@/lib/osPicker';

/**
 * The operating systems an app runs on, ticked from the panel's own OS
 * templates (docs/adr/0018 §5): one row per family, one chip per version.
 * lib/osPicker writes the families and lowest and highest versions.
 */
const props = defineProps<{
    templates: TemplateVersion[];
    groups: OsGroups;
    readonly?: boolean;
}>();

const requires = defineModel<Requires>({ required: true });

const NAMES: Record<string, string> = {
    debian: 'Debian',
    ubuntu: 'Ubuntu',
    almalinux: 'AlmaLinux',
    rocky: 'Rocky Linux',
    centos: 'CentOS',
    fedora: 'Fedora',
    opensuse: 'openSUSE',
    archlinux: 'Arch Linux',
};

const name = (family: string) => NAMES[family] ?? family;

const families = computed(() => familiesOf(props.templates));

const ticked = (family: string) =>
    selected(requires.value, family, props.templates, props.groups);

// Families the recipe allows that have no template here: kept as they are.
const others = computed(() =>
    expandedFamilies(requires.value, props.groups).filter(
        (family) =>
            !(family in families.value) &&
            requires.value.max_versions?.[family] !== '0',
    ),
);

const range = (family: string) => {
    const min = requires.value.min_versions?.[family];
    const max = requires.value.max_versions?.[family];

    return [min ? `≥ ${min}` : '', max ? `≤ ${max}` : '']
        .filter(Boolean)
        .join(' ');
};

const flip = (family: string, version: string) => {
    requires.value = toggle(
        requires.value,
        family,
        version,
        props.templates,
        props.groups,
    );
};

const newer = (family: string, value: boolean | 'indeterminate') => {
    requires.value = setNewer(
        requires.value,
        family,
        value === true,
        props.templates,
        props.groups,
    );
};
</script>

<template>
    <div class="grid gap-3" data-test="os-picker">
        <p
            v-if="Object.keys(families).length === 0"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
        >
            {{
                $t(
                    'There are no active Linux OS templates yet. Add them under OS templates; until then the operating systems can only be changed in the YAML view.',
                )
            }}
        </p>
        <p
            v-else-if="(requires.os ?? []).length === 0"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            data-test="any-os"
        >
            {{
                $t(
                    'Nothing is ticked, so the app is offered on every Linux template. Tick the versions it was written for.',
                )
            }}
        </p>

        <div
            v-for="(versions, family) in families"
            :key="family"
            class="flex flex-wrap items-center gap-2"
        >
            <span class="w-28 text-sm font-medium">{{ name(family) }}</span>
            <button
                v-for="version in versions"
                :key="version"
                type="button"
                class="rounded-full border px-3 py-1 text-xs transition-colors disabled:cursor-not-allowed"
                :class="
                    ticked(family).includes(version)
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-muted'
                "
                :aria-pressed="ticked(family).includes(version)"
                :disabled="readonly"
                :data-test="`os-${family}-${version}`"
                @click="flip(family, version)"
            >
                {{ version }}
            </button>
            <label
                v-if="ticked(family).length"
                class="ml-2 flex items-center gap-2 text-xs text-muted-foreground"
            >
                <Checkbox
                    :model-value="allowsNewer(requires, family)"
                    :disabled="readonly"
                    @update:model-value="newer(family, $event)"
                />
                {{ $t('Also newer versions added later') }}
            </label>
        </div>

        <p v-if="others.length" class="text-xs text-muted-foreground">
            {{ $t('Also allowed, without an OS template in this panel:') }}
            <span v-for="(family, index) in others" :key="family"
                >{{ index ? ', ' : '' }}{{ name(family) }}
                {{ range(family) }}</span
            >
        </p>
        <p class="text-xs text-muted-foreground">
            {{
                $t(
                    'Ticking a version selects the versions between it and the others. The server checks its operating system again before a script runs.',
                )
            }}
        </p>
    </div>
</template>
