<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import SkipLink from '@/components/SkipLink.vue';
import { Toaster } from '@/components/ui/sonner';
import UnsavedChangesGuard from '@/components/UnsavedChangesGuard.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="header">
        <SkipLink />
        <AppHeader :breadcrumbs="breadcrumbs" />
        <AppContent
            id="main-content"
            tabindex="-1"
            variant="header"
            class="outline-none"
        >
            <slot />
        </AppContent>
        <Toaster />
        <UnsavedChangesGuard />
    </AppShell>
</template>
