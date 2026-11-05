<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Copies one machine value (a root password, an address, an app output) to
 * the clipboard: a 32px icon button whose icon turns into a check for a
 * moment, with "Copied" read out politely. Browsers offer the clipboard only
 * on HTTPS and localhost; without it the button is not shown and the value
 * stays selectable.
 */
const props = defineProps<{
    value: string;
    /** English key naming what is copied, e.g. "Copy root password". */
    label: string;
}>();

const canCopy =
    typeof navigator !== 'undefined' && navigator.clipboard !== undefined;

const copied = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.value);
    } catch {
        return;
    }

    copied.value = true;
    clearTimeout(timer);
    timer = setTimeout(() => (copied.value = false), 2000);
};

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <span v-if="canCopy" class="inline-flex shrink-0">
        <Button
            type="button"
            variant="ghost"
            size="icon"
            class="h-8 w-8"
            :aria-label="$t(label)"
            :title="copied ? $t('Copied') : $t(label)"
            data-test="copy-button"
            @click="copy"
        >
            <Check
                v-if="copied"
                class="h-4 w-4 text-status-success-foreground"
                aria-hidden="true"
            />
            <Copy v-else class="h-4 w-4" aria-hidden="true" />
        </Button>
        <span class="sr-only" role="status">{{
            copied ? $t('Copied') : ''
        }}</span>
    </span>
</template>
