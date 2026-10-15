<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Languages } from '@lucide/vue';
import { applyLocale } from '@/lib/i18n';
import { update } from '@/routes/locale';

// Language names are always shown in their own language.
const names: Record<string, string> = {
    en: 'English',
    tr: 'Türkçe',
};

const page = usePage();

const switchTo = (locale: string): void => {
    if (locale === page.props.locale) {
        return;
    }

    router.patch(
        update().url,
        { locale },
        {
            preserveScroll: true,
            onSuccess: () => void applyLocale(locale),
        },
    );
};
</script>

<template>
    <div
        class="inline-flex items-center gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
        role="group"
        :aria-label="$t('Language')"
    >
        <Languages class="mx-1.5 h-4 w-4 text-neutral-500" />
        <button
            v-for="locale in page.props.availableLocales"
            :key="locale"
            type="button"
            :lang="locale"
            :aria-pressed="page.props.locale === locale"
            @click="switchTo(locale)"
            :class="[
                'rounded-md px-3 py-1 text-sm transition-colors',
                page.props.locale === locale
                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
        >
            {{ names[locale] ?? locale }}
        </button>
    </div>
</template>
