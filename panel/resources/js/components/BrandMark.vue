<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/**
 * The uploaded logo of Panel settings > Branding (docs/adr/0053), with its
 * dark-mode version when there is one, else the Proxmotion mark. Size it
 * with a class; the logo is scaled to fit.
 */
defineOptions({
    inheritAttrs: false,
});

const page = usePage();
const branding = computed(() => page.props.branding);
</script>

<template>
    <template v-if="branding.logo_url">
        <img
            :src="branding.logo_url"
            :alt="branding.name"
            v-bind="$attrs"
            class="object-contain"
            :class="{ 'dark:hidden': branding.logo_dark_url }"
            data-test="brand-logo"
        />
        <img
            v-if="branding.logo_dark_url"
            :src="branding.logo_dark_url"
            :alt="branding.name"
            v-bind="$attrs"
            class="hidden object-contain dark:block"
            data-test="brand-logo-dark"
        />
    </template>
    <AppLogoIcon v-else v-bind="$attrs" />
</template>
