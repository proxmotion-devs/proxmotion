<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * One question on the Help page. It opens by itself when the address
 * points to it (e.g. /admin/help#iso-download).
 */
const props = defineProps<{
    id: string;
    /** English key, translated here. */
    title: string;
}>();

const open = ref(false);

const openWhenLinked = () => {
    if (window.location.hash === `#${props.id}`) {
        open.value = true;
    }
};

onMounted(() => {
    openWhenLinked();
    window.addEventListener('hashchange', openWhenLinked);
});
onBeforeUnmount(() => window.removeEventListener('hashchange', openWhenLinked));
</script>

<template>
    <details
        :id="id"
        :open="open"
        class="group scroll-mt-4 rounded-lg border"
        @toggle="open = ($event.target as HTMLDetailsElement).open"
    >
        <summary
            class="flex cursor-pointer list-none items-center gap-2 p-3 font-medium select-none hover:bg-muted/40 [&::-webkit-details-marker]:hidden"
        >
            <ChevronRight
                class="h-4 w-4 shrink-0 transition-transform group-open:rotate-90"
            />
            {{ $t(title) }}
        </summary>
        <div
            class="flex flex-col gap-2 border-t p-3 pl-9 text-sm text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_h4]:mt-2 [&_h4]:font-medium [&_h4]:text-foreground [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_pre]:font-mono [&_pre]:text-xs [&_pre]:text-foreground [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5"
        >
            <slot />
        </div>
    </details>
</template>
