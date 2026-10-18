<?php

namespace Tests\Feature;

use App\Support\Locales;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Translation keys are the English source strings. These tests make sure every
 * key the UI uses has a translation in every non-English locale. Detected:
 *
 * - $t('…'), trans('…'), __('…') and $this->toast('type', '…'); also $t("…") and trans("…")
 * - static title/description/label/hint/submit-label attributes (Heading, FormField, <Head>, …)
 * - string literals in bound :title/:description/:label/:hint/:submit-label attributes
 * - title/description/label/detail/buttonText properties in scripts (breadcrumbs, nav items, …)
 */
class TranslationsTest extends TestCase
{
    private const LITERAL = "'((?:[^'\\\\]|\\\\.)*)'";

    private const DOUBLE_QUOTED = '"((?:[^"\\\\]|\\\\.)*)"';

    private const TRANSLATED_ATTRIBUTES = 'title|description|label|hint|submit-label';

    public function test_every_literal_ui_key_is_translated()
    {
        $keys = $this->usedKeys();

        $this->assertNotEmpty($keys, 'No translation keys found — is the scanner broken?');

        foreach ($this->translatedLocales() as $locale) {
            $translations = $this->jsonTranslations($locale);
            $missing = array_values(array_filter($keys, fn (string $key) => ! array_key_exists($key, $translations)));

            $this->assertSame([], $missing, "Missing translations in lang/{$locale}.json");
        }
    }

    public function test_every_php_language_file_exists_in_every_locale()
    {
        $english = $this->phpLangFiles('en');

        foreach ($this->translatedLocales() as $locale) {
            $missing = array_values(array_diff($english, $this->phpLangFiles($locale)));

            $this->assertSame([], $missing, "Missing PHP language files in lang/{$locale}/");
        }
    }

    public function test_json_translations_are_not_empty()
    {
        foreach ($this->translatedLocales() as $locale) {
            $empty = array_keys(array_filter($this->jsonTranslations($locale), fn (string $value) => trim($value) === ''));

            $this->assertSame([], $empty, "Empty translations in lang/{$locale}.json");
        }
    }

    /**
     * @return list<string>
     */
    private function usedKeys(): array
    {
        $attributes = self::TRANSLATED_ATTRIBUTES;

        $patterns = [
            // $t('key'), trans('key'), __('key')
            '/(?:\$t|\btrans|\b__)\(\s*'.self::LITERAL.'/',
            // $t("key's"): the formatter switches to double quotes for keys with an apostrophe
            '/(?:\$t|\btrans)\(\s*'.self::DOUBLE_QUOTED.'/',
            // $t(condition ? 'a' : 'b')
            '/\$t\(\s*[\w.]+\s*\?\s*'.self::LITERAL.'\s*:\s*'.self::LITERAL.'/',
            // $this->toast('success', 'key')
            '/->toast\(\s*\'\w+\',\s*'.self::LITERAL.'/',
            // <Heading title="key" />, <FormField label="key" />, <Head title="key" />
            '/(?<![:\w-])(?:'.$attributes.')="([^"{}<>]*[A-Za-z][^"{}<>]*)"/',
            // breadcrumbs, nav items, cards, option lists: { title: 'key' }, { label: 'key' }
            '/\b(?:title|description|label|detail|buttonText):\s*'.self::LITERAL.'/',
        ];

        $finder = Finder::create()
            ->files()
            ->in([resource_path('js'), app_path()])
            ->exclude(['components/ui', 'actions', 'routes', 'wayfinder'])
            ->name(['*.vue', '*.ts', '*.php'])
            ->notName('*.test.ts');

        $keys = [];

        foreach ($finder as $file) {
            $contents = $file->getContents();

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $contents, $matches);

                foreach (array_slice($matches, 1) as $group) {
                    array_push($keys, ...array_filter($group, fn (string $key) => $key !== ''));
                }
            }

            // :hint="cluster ? 'key a' : 'key b'" — only literals that look like text, not
            // values being compared (e.g. === 'routed').
            preg_match_all('/:(?:'.$attributes.')="([^"]*)"/', $contents, $bound);

            foreach ($bound[1] as $expression) {
                preg_match_all('/'.self::LITERAL.'/', $expression, $literals);

                foreach ($literals[1] as $literal) {
                    if (preg_match('/[A-Z]|[a-z] [a-z]/', $literal) === 1) {
                        $keys[] = $literal;
                    }
                }
            }
        }

        $keys = array_values(array_unique(array_map(stripslashes(...), $keys)));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function translatedLocales(): array
    {
        return array_values(array_diff(Locales::SUPPORTED, ['en']));
    }

    /**
     * @return array<string, string>
     */
    private function jsonTranslations(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        $this->assertFileExists($path);

        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $translations;
    }

    /**
     * @return list<string>
     */
    private function phpLangFiles(string $locale): array
    {
        $files = array_map('basename', glob(lang_path("{$locale}/*.php")) ?: []);
        sort($files);

        return $files;
    }
}
