import type { App } from 'vue';
import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';

// Translation keys are the English source strings; `lang/<locale>.json` holds
// the translations and the Vite plugin adds Laravel's PHP lang files as `php_<locale>.json`.
const languages = import.meta.glob<{ default: Record<string, string> }>(
    '../../../lang/*.json',
);

export function initialLocale(): string {
    return document.documentElement.lang || 'en';
}

export function installI18n(app: App): void {
    app.use(i18nVue, {
        lang: initialLocale(),
        fallbackLang: 'en',
        resolve: async (lang: string) => {
            const loader = languages[`../../../lang/${lang}.json`];

            return loader ? await loader() : { default: {} };
        },
    });
}

export async function applyLocale(locale: string): Promise<void> {
    document.documentElement.lang = locale;
    await loadLanguageAsync(locale);
}
