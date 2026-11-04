<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import type { LeavingVisit } from '@/lib/unsavedChanges';
import { hasUnsavedChanges, leavesPage } from '@/lib/unsavedChanges';

/**
 * Asks before edits are lost (`useUnsavedChanges`): a visit to another page
 * waits for the panel's own question, closing or reloading the tab for the
 * browser's. Mounted once by the app layouts.
 */
const open = ref(false);
const pending = ref<string | null>(null);
// Set for the one visit the administrator chose to make anyway.
let leaving = false;

const onBefore = (event: CustomEvent<{ visit: LeavingVisit }>) => {
    const visit = event.detail.visit;

    if (!leavesPage(visit, new URL(window.location.href))) {
        return;
    }

    if (leaving) {
        leaving = false;

        return;
    }

    if (!hasUnsavedChanges()) {
        return;
    }

    event.preventDefault();
    pending.value = visit.url.href;
    open.value = true;
};

const onBeforeUnload = (event: BeforeUnloadEvent) => {
    if (hasUnsavedChanges()) {
        event.preventDefault();
        // Older browsers only ask when a value is set.
        event.returnValue = '';
    }
};

const leave = () => {
    if (pending.value === null) {
        return;
    }

    leaving = true;
    router.visit(pending.value);
    pending.value = null;
};

let stopListening: (() => void) | undefined;

onMounted(() => {
    stopListening = router.on('before', onBefore);
    window.addEventListener('beforeunload', onBeforeUnload);
});

onBeforeUnmount(() => {
    stopListening?.();
    window.removeEventListener('beforeunload', onBeforeUnload);
});
</script>

<template>
    <ConfirmDialog
        v-model:open="open"
        title="Leave without saving?"
        :description="
            trans(
                'The changes on this page are not saved yet. They are lost when you leave it.',
            )
        "
        confirm-label="Leave without saving"
        destructive
        @confirm="leave"
    />
</template>
