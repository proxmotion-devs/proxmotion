<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Domain\Settings\PanelSettings;

/**
 * Makes up the root passwords the panel sets itself: a new server ordered
 * without one, a reinstall, a reset without a chosen password. The format is
 * set on Panel settings (docs/adr/0038): a random part from the chosen
 * characters, with an optional prefix and suffix.
 */
final readonly class PasswordGenerator
{
    private const string LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const string UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const string DIGITS = '0123456789';

    // Only characters that survive copying, typing in a console and a shell.
    private const string SYMBOLS = '!@#%+=.-_';

    /** @var array<string, string> */
    private const array KINDS = [
        'lower' => self::LOWER,
        'upper' => self::UPPER,
        'digit' => self::DIGITS,
        'symbol' => self::SYMBOLS,
    ];

    /** @var array<string, list<string>> the kinds each set draws its random part from */
    private const array SETS = [
        'lower' => ['lower', 'digit'],
        'mixed' => ['lower', 'upper', 'digit'],
        'symbols' => ['lower', 'upper', 'digit', 'symbol'],
    ];

    /**
     * A password in the saved format. Its random part holds every kind of
     * its set, so it never comes out with fewer than the set's kinds.
     */
    public function generate(): string
    {
        // .env values are not checked like the page's: an unknown set or a length out of range falls back to the page's limits.
        $characters = (string) PanelSettings::get('password_characters');
        $kinds = self::SETS[isset(self::SETS[$characters]) ? $characters : 'mixed'];
        $alphabet = implode('', array_map(fn (string $kind): string => self::KINDS[$kind], $kinds));
        $length = min(64, max(12, (int) PanelSettings::get('password_length')));

        do {
            $random = '';

            for ($i = 0; $i < $length; $i++) {
                $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (! $this->containsEveryKind($random, $kinds));

        return (string) PanelSettings::get('password_prefix').$random.(string) PanelSettings::get('password_suffix');
    }

    /**
     * How many kinds of characters (lowercase, uppercase, digits, symbols) a
     * format has, counting its random part and its prefix and suffix.
     * Windows needs at least three in a password (docs/adr/0038).
     */
    public static function kinds(string $characters, string ...$affixes): int
    {
        $count = 0;

        foreach (self::KINDS as $kind => $alphabet) {
            $inRandom = in_array($kind, self::SETS[$characters], true);
            $inAffix = array_filter($affixes, fn (string $affix): bool => strpbrk($affix, $alphabet) !== false) !== [];

            $count += ($inRandom || $inAffix) ? 1 : 0;
        }

        return $count;
    }

    /**
     * @param  list<string>  $kinds
     */
    private function containsEveryKind(string $random, array $kinds): bool
    {
        foreach ($kinds as $kind) {
            if (strpbrk($random, self::KINDS[$kind]) === false) {
                return false;
            }
        }

        return true;
    }
}
