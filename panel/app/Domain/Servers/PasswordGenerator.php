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
     * its set, so it never comes out with fewer than the set's kinds, and
     * starts with a letter, so that without a prefix it fits cloud-init's
     * user data (fitsCloudInit()).
     */
    public function generate(): string
    {
        // .env values are not checked like the page's: an unknown set or a length out of range falls back to the page's limits.
        $characters = (string) PanelSettings::get('password_characters');
        $kinds = self::SETS[isset(self::SETS[$characters]) ? $characters : 'mixed'];
        $length = min(64, max(12, (int) PanelSettings::get('password_length')));

        return (string) PanelSettings::get('password_prefix').$this->random($kinds, $length).(string) PanelSettings::get('password_suffix');
    }

    /**
     * A password that always fits cloud-init's user data, whatever the saved
     * format: 24 letters and digits, starting with a letter, with lowercase,
     * uppercase and digits (Windows wants three kinds). The panel gives it to
     * Proxmox in place of a password that does not fit, until the guest
     * agent can set that one.
     */
    public function temporary(): string
    {
        return $this->random(self::SETS['mixed'], 24);
    }

    /**
     * Whether Proxmox can put the password into cloud-init's user data as it
     * is. For Windows VMs Proxmox writes it there unhashed and unquoted
     * ("password: <password>"), and Cloudbase-Init's UserDataPlugin fails on
     * YAML that does not parse (a first character like @, % or ` cannot
     * start a value, # starts a comment, ": " ends it, a line break starts a
     * new key). One that starts with a letter and holds only printable ASCII
     * without spaces and colons is always read back as the same string.
     */
    public static function fitsCloudInit(string $password): bool
    {
        return preg_match('/^[A-Za-z][\x21-\x39\x3B-\x7E]*$/D', $password) === 1;
    }

    /**
     * @param  list<string>  $kinds
     */
    private function random(array $kinds, int $length): string
    {
        $alphabet = implode('', array_map(fn (string $kind): string => self::KINDS[$kind], $kinds));
        $letters = implode('', array_map(fn (string $kind): string => self::KINDS[$kind], array_intersect($kinds, ['lower', 'upper'])));

        do {
            $random = $letters[random_int(0, strlen($letters) - 1)];

            for ($i = 1; $i < $length; $i++) {
                $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (! $this->containsEveryKind($random, $kinds));

        return $random;
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
