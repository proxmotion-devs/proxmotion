import type { App } from 'vue';
import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';

// Translation keys are the English source strings; `lang/<locale>.json` holds
// the translations and the Vite plugin adds Laravel's PHP lang files as `php_<locale>.json`.
// Loaded eagerly so the first render is already in the right language (no flash of English).
const languages = import.meta.glob<{ default: Record<string, string> }>(
    '../../../lang/*.json',
    { eager: true },
);

export function initialLocale(): string {
    // There is no document during server-side rendering.
    return typeof document !== 'undefined'
        ? document.documentElement.lang || 'en'
        : 'en';
}

/**
 * @param locale the page's locale; required during server-side rendering
 */
export function installI18n(app: App, locale?: string): void {
    app.use(i18nVue, {
        lang: locale || initialLocale(),
        fallbackLang: 'en',
        resolve: (lang: string) =>
            languages[`../../../lang/${lang}.json`]?.default ?? {},
    });
}

export async function applyLocale(locale: string): Promise<void> {
    document.documentElement.lang = locale;
    await loadLanguageAsync(locale);
}
