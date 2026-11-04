import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { provideSSRWidth } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeConnectionToast } from '@/lib/connectionToast';
import { initializeFlashToast } from '@/lib/flashToast';
import { installI18n } from '@/lib/i18n';

// The name of Panel settings > Branding (docs/adr/0053), read on every title.
const appName = () => usePage().props?.name || 'Proxmotion';

void createInertiaApp({
    // Page titles are passed as English keys and translated here.
    title: (title) => (title ? `${trans(title)} - ${appName()}` : appName()),
    layout: (name) => {
        switch (true) {
            // Error pages also open for visitors who are not signed in.
            case name.startsWith('auth/'):
            case name.startsWith('errors/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case name.startsWith('console/'):
                // The console uses the whole window.
                return [];
            default:
                return AppLayout;
        }
    },
    withApp: (app, { page }) => {
        installI18n(app, page.props.locale);

        // The server cannot know the viewport, so media queries (the sidebar's
        // mobile mode) are evaluated against a desktop width during SSR and
        // hydration; the real width takes over once the app has mounted.
        provideSSRWidth(1024, app);

        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// ...and say so when the panel cannot be reached at all.
initializeConnectionToast();
