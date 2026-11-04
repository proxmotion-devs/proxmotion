<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, LifeBuoy, Lightbulb, X } from '@lucide/vue';
import { ref } from 'vue';
import { help } from '@/routes/admin';

/**
 * A short how-to at the top of an admin page. Administrators can hide it;
 * the choice is remembered per guide in this browser only.
 */
const props = defineProps<{
    /** Remembers whether the guide is hidden, e.g. "os-templates". */
    id: string;
    /** English key, translated here. */
    title: string;
    /** Section of the Help page with more detail, e.g. "os-templates". */
    topic?: string;
}>();

const storageKey = `proxmotion.guide.${props.id}`;
// Read before the first render, so a hidden guide never opens and then
// collapses (the panel renders in the browser only).
const storedOpen = () => {
    try {
        return localStorage.getItem(storageKey) !== 'hidden';
    } catch {
        // Storage can be blocked; the guide then stays open.
        return true;
    }
};
const open = ref(storedOpen());

const setOpen = (value: boolean) => {
    open.value = value;

    try {
        if (value) {
            localStorage.removeItem(storageKey);
        } else {
            localStorage.setItem(storageKey, 'hidden');
        }
    } catch {
        // Not remembered, nothing else to do.
    }
};
</script>

<template>
    <section
        v-if="open"
        class="relative rounded-xl border border-guide/30 bg-guide/5 p-4 text-sm"
        :data-test="`guide-${id}`"
    >
        <button
            type="button"
            class="absolute top-2 right-2 inline-flex size-8 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
            :aria-label="$t('Hide guide')"
            :title="$t('Hide guide')"
            data-test="guide-hide"
            @click="setOpen(false)"
        >
            <X class="h-4 w-4" />
        </button>
        <h2 class="flex items-center gap-2 pr-8 font-medium">
            <Lightbulb class="h-4 w-4 shrink-0 text-guide-icon" />
            {{ $t(title) }}
        </h2>
        <div
            class="mt-2 text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_p]:mt-2 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5"
        >
            <slot />
        </div>
        <Link
            v-if="topic"
            :href="`${help().url}#${topic}`"
            class="mt-3 inline-flex items-center gap-1 rounded-sm text-guide-link underline-offset-4 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <LifeBuoy class="h-4 w-4" />
            {{ $t('More in Help') }}
        </Link>
    </section>
    <button
        v-else
        type="button"
        class="-mx-2 flex h-8 items-center gap-1 self-start rounded-md px-2 text-xs text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
        data-test="guide-show"
        @click="setOpen(true)"
    >
        <Lightbulb class="h-3.5 w-3.5" />
        {{ $t('Show guide: :title', { title: $t(title) }) }}
        <ChevronDown class="h-3.5 w-3.5" />
    </button>
</template>
