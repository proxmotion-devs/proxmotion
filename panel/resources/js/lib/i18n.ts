import type { App } from 'vue';
import { I18n, i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';

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
    const lang = locale || initialLocale();

    app.use(i18nVue, {
        lang,
        fallbackLang: 'en',
        resolve: (name: string) =>
            languages[`../../../lang/${name}.json`]?.default ?? {},
    });

    // In the browser the plugin always loads the language asynchronously, so
    // hydration would render English against the server's translated HTML.
    // The messages are bundled, so apply them before the first render.
    I18n.getSharedInstance().loadLanguage(lang);
}

export async function applyLocale(locale: string): Promise<void> {
    document.documentElement.lang = locale;
    await loadLanguageAsync(locale);
}
