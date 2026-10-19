<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    allowsNewer,
    anyOs,
    coveredDistributions,
    distributionsOf,
    selected,
    setNewer,
    toggle,
} from '@/lib/osPicker';
import type { Requires, TemplateVersion } from '@/lib/osPicker';

import type { Distribution } from '@/lib/osTemplates';

/**
 * The operating systems an app runs on, ticked from the panel's own OS
 * templates (docs/adr/0018 §5): one row per distribution, one chip per
 * version. lib/osPicker writes the distributions and lowest and highest
 * versions (docs/adr/0024 §4).
 */
const props = defineProps<{
    templates: TemplateVersion[];
    catalog: Distribution[];
    readonly?: boolean;
}>();

const requires = defineModel<Requires>({ required: true });

const name = (distribution: string) =>
    props.catalog.find((entry) => entry.id === distribution)?.name ??
    distribution;

const distributions = computed(() => distributionsOf(props.templates));

const ticked = (distribution: string) =>
    selected(requires.value, distribution, props.templates, props.catalog);

// Distributions the recipe allows that have no template here: kept as they are.
const others = computed(() =>
    coveredDistributions(requires.value, props.catalog).filter(
        (distribution) => !(distribution in distributions.value),
    ),
);

const range = (distribution: string) => {
    const min = requires.value.min_versions?.[distribution];
    const max = requires.value.max_versions?.[distribution];

    return [min ? `≥ ${min}` : '', max ? `≤ ${max}` : '']
        .filter(Boolean)
        .join(' ');
};

const flip = (distribution: string, version: string) => {
    requires.value = toggle(
        requires.value,
        distribution,
        version,
        props.templates,
        props.catalog,
    );
};

const newer = (distribution: string, value: boolean | 'indeterminate') => {
    requires.value = setNewer(
        requires.value,
        distribution,
        value === true,
        props.templates,
        props.catalog,
    );
};
</script>

<template>
    <div class="grid gap-3" data-test="os-picker">
        <p
            v-if="Object.keys(distributions).length === 0"
            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
        >
            {{
                $t(
                    'There are no active Linux OS templates yet. Add them under OS templates; until then the operating systems can only be changed in the YAML view.',
                )
            }}
        </p>
        <p
            v-else-if="anyOs(requires)"
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
            v-for="(versions, family) in distributions"
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
