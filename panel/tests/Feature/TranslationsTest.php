<?php

namespace Tests\Feature;

use App\Support\Locales;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Translation keys are the English source strings. These tests make sure every
 * key used as a string literal has a translation in every non-English locale.
 *
 * Keys passed indirectly (e.g. page titles, breadcrumb and Heading props) are
 * not detected here and must be added to `lang/<locale>.json` by hand.
 */
class TranslationsTest extends TestCase
{
    private const LITERAL = "'((?:[^'\\\\]|\\\\.)*)'";

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
        $patterns = [
            // $t('key'), trans('key'), __('key')
            '/(?:\$t|\btrans|\b__)\(\s*'.self::LITERAL.'/',
            // $t(condition ? 'a' : 'b')
            '/\$t\(\s*[\w.]+\s*\?\s*'.self::LITERAL.'\s*:\s*'.self::LITERAL.'/',
        ];

        $finder = Finder::create()
            ->files()
            ->in([resource_path('js'), app_path()])
            ->exclude(['components/ui', 'actions', 'routes', 'wayfinder'])
            ->name(['*.vue', '*.ts', '*.php']);

        $keys = [];

        foreach ($finder as $file) {
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $file->getContents(), $matches);

                foreach (array_slice($matches, 1) as $group) {
                    foreach ($group as $key) {
                        if ($key !== '') {
                            $keys[] = stripslashes($key);
                        }
                    }
                }
            }
        }

        $keys = array_values(array_unique($keys));
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
