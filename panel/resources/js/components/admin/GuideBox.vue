<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, LifeBuoy, Lightbulb, X } from '@lucide/vue';
import { onMounted, ref } from 'vue';
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
const open = ref(true);

onMounted(() => {
    try {
        open.value = localStorage.getItem(storageKey) !== 'hidden';
    } catch {
        // Storage can be blocked; the guide then stays open.
    }
});

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
        class="relative rounded-xl border border-sky-500/30 bg-sky-500/5 p-4 text-sm"
        :data-test="`guide-${id}`"
    >
        <button
            type="button"
            class="absolute top-3 right-3 rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
            :aria-label="$t('Hide guide')"
            :title="$t('Hide guide')"
            data-test="guide-hide"
            @click="setOpen(false)"
        >
            <X class="h-4 w-4" />
        </button>
        <h3 class="flex items-center gap-2 pr-8 font-medium">
            <Lightbulb class="h-4 w-4 text-sky-600 dark:text-sky-400" />
            {{ $t(title) }}
        </h3>
        <div
            class="mt-2 text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_p]:mt-2 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5"
        >
            <slot />
        </div>
        <Link
            v-if="topic"
            :href="`${help().url}#${topic}`"
            class="mt-3 inline-flex items-center gap-1 text-sky-700 hover:underline dark:text-sky-400"
        >
            <LifeBuoy class="h-4 w-4" />
            {{ $t('More in Help') }}
        </Link>
    </section>
    <button
        v-else
        type="button"
        class="flex items-center gap-1 self-start text-xs text-muted-foreground hover:text-foreground"
        data-test="guide-show"
        @click="setOpen(true)"
    >
        <Lightbulb class="h-3.5 w-3.5" />
        {{ $t('Show guide: :title', { title: $t(title) }) }}
        <ChevronDown class="h-3.5 w-3.5" />
    </button>
</template>
