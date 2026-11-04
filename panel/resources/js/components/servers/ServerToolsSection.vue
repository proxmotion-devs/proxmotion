<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { ref, watch } from 'vue';

/**
 * The server page's tools for administrators rather than everyday support
 * (checks from inside, scripts, the reinstall limit, moves), folded away
 * under one heading. It opens by itself when something there needs a look,
 * and otherwise stays the way it was left, remembered per browser.
 */
const props = defineProps<{
    /** English keys of what is inside, listed under the heading. */
    contents: string[];
    /** A failed check or a running script: shown open. */
    attention?: boolean;
}>();

const storageKey = 'proxmotion.server-tools';

const stored = (): boolean => {
    try {
        return localStorage.getItem(storageKey) === 'open';
    } catch {
        return false;
    }
};

const open = ref(props.attention === true || stored());

watch(
    () => props.attention,
    (value) => {
        if (value) {
            open.value = true;
        }
    },
);

const onToggle = (event: Event) => {
    open.value = (event.target as HTMLDetailsElement).open;

    try {
        if (open.value) {
            localStorage.setItem(storageKey, 'open');
        } else {
            localStorage.removeItem(storageKey);
        }
    } catch {
        // Private windows: the choice is simply not remembered.
    }
};
</script>

<template>
    <details
        class="group"
        :open="open"
        data-test="server-tools"
        @toggle="onToggle"
    >
        <summary
            class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg py-1 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&::-webkit-details-marker]:hidden"
        >
            <span class="grid gap-0.5">
                <h2 class="font-medium">
                    {{ $t('Diagnostics and administration') }}
                </h2>
                <span class="text-sm text-muted-foreground">
                    {{ contents.map((item) => $t(item)).join(' · ') }}
                </span>
            </span>
            <ChevronDown
                class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                aria-hidden="true"
            />
        </summary>
        <div class="mt-4 flex flex-col gap-6">
            <slot />
        </div>
    </details>
</template>
