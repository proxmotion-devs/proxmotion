<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandMark from '@/components/BrandMark.vue';

const page = usePage();
const branding = computed(() => page.props.branding);
</script>

<template>
    <!-- An uploaded logo has its own colours (docs/adr/0053). -->
    <div
        v-if="branding.logo_url"
        class="flex aspect-square size-8 items-center justify-center"
    >
        <BrandMark class="size-8" />
    </div>
    <div
        v-else
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <!-- The default dark theme's sidebar-primary-foreground is white on white. -->
        <AppLogoIcon
            class="size-5 fill-current"
            :class="
                branding.color
                    ? 'text-sidebar-primary-foreground'
                    : 'text-white dark:text-black'
            "
        />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            branding.name
        }}</span>
    </div>
</template>
