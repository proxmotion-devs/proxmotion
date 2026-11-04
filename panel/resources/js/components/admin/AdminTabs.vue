<script setup lang="ts">
import { ref } from 'vue';

/**
 * Page tabs as DESIGN.md describes them (the server page's): a row on a
 * hairline with a 2px underline in the foreground colour, never the
 * provider accent, so a pale brand cannot hide the selection; arrow keys,
 * Home and End move between tabs, only the selected one is in the tab
 * order, and the row scrolls sideways on phones. The parent decides
 * whether a tab may be left (`select`) and renders the panel with
 * role="tabpanel", id `panelId` and aria-labelledby the selected tab's id
 * (`tabId(value)`).
 */
const props = defineProps<{
    tabs: { value: string; label: string }[];
    /** The selected tab. */
    modelValue: string;
    /** English key naming the row for screen readers. */
    label: string;
    /** Prefix of the tabs' ids, unique on the page. */
    idPrefix: string;
    panelId: string;
}>();

const emit = defineEmits<{ select: [value: string] }>();

const row = ref<HTMLElement | null>(null);

const tabId = (value: string) => `${props.idPrefix}-${value}`;

const move = (event: KeyboardEvent) => {
    const values = props.tabs.map((tab) => tab.value);
    const current = values.indexOf(props.modelValue);
    const next = {
        ArrowRight: (current + 1) % values.length,
        ArrowLeft: (current - 1 + values.length) % values.length,
        Home: 0,
        End: values.length - 1,
    }[event.key];

    if (next === undefined || current === -1) {
        return;
    }

    event.preventDefault();
    emit('select', values[next]);
    // Focus follows once the parent has switched (it may refuse).
    requestAnimationFrame(() =>
        row.value
            ?.querySelector<HTMLElement>('[aria-selected="true"]')
            ?.focus(),
    );
};

defineExpose({ tabId });
</script>

<template>
    <div
        ref="row"
        role="tablist"
        :aria-label="$t(label)"
        class="-mb-px flex [scrollbar-width:none] gap-1 overflow-x-auto"
        @keydown="move"
    >
        <button
            v-for="tab in tabs"
            :id="tabId(tab.value)"
            :key="tab.value"
            type="button"
            role="tab"
            :aria-selected="modelValue === tab.value"
            :aria-controls="modelValue === tab.value ? panelId : undefined"
            :tabindex="modelValue === tab.value ? 0 : -1"
            class="shrink-0 border-b-2 px-3 py-2 text-sm whitespace-nowrap outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
            :class="
                modelValue === tab.value
                    ? 'border-foreground font-medium'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
            "
            :data-test="`tab-${tab.value}`"
            @click="emit('select', tab.value)"
        >
            {{ $t(tab.label) }}
        </button>
    </div>
</template>
