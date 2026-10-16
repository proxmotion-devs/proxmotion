<?php

namespace Proxmotion\Whmcs;

/**
 * Client area texts in the customer's WHMCS language (lang/<language>.php),
 * English when there is no file for it.
 */
class Lang
{
    /** @var array<string, string> */
    private $strings;

    /**
     * @param array<string, string> $strings
     */
    public function __construct(array $strings)
    {
        $this->strings = $strings;
    }

    /**
     * @param array<string, mixed> $params the WHMCS module parameters
     */
    public static function forParams(array $params): self
    {
        $language = '';

        if (isset($params['clientsdetails']['language']) && is_string($params['clientsdetails']['language'])) {
            $language = $params['clientsdetails']['language'];
        }

        if ($language === '' && isset($GLOBALS['CONFIG']['Language']) && is_string($GLOBALS['CONFIG']['Language'])) {
            $language = $GLOBALS['CONFIG']['Language'];
        }

        return self::load($language);
    }

    public static function load(string $language): self
    {
        $english = require __DIR__ . '/../lang/english.php';
        $language = strtolower(preg_replace('/[^a-z_-]/i', '', $language) ?: '');
        $file = __DIR__ . '/../lang/' . $language . '.php';
        $strings = $language !== 'english' && $language !== '' && is_file($file) ? array_merge($english, require $file) : $english;

        return new self($strings);
    }

    /**
     * @param array<string, string|int> $replace ":name" placeholders
     */
    public function get(string $key, array $replace = []): string
    {
        $text = isset($this->strings[$key]) ? $this->strings[$key] : $key;

        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->strings;
    }
}
