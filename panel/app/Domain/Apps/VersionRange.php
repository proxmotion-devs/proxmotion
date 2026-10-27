<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * The installed versions an upgrade script accepts (`lifecycle.upgrade.from`,
 * docs/adr/0016 §1): space-separated comparators that must all hold, e.g.
 * ">=1.0.0 <1.2.0". An administrator's edit of a bundled version
 * (1.1.0-local.2, docs/adr/0018 §2) is compared as the version it was made
 * from (1.1.0).
 */
final readonly class VersionRange
{
    private const COMPARATOR = '/^(>=|<=|>|<|=)(\d+\.\d+\.\d+)$/';

    /**
     * @param  list<array{string, string}>  $comparators  operator and version
     */
    private function __construct(private array $comparators) {}

    public static function parse(string $range): ?self
    {
        $comparators = [];

        foreach (preg_split('/\s+/', trim($range)) ?: [] as $part) {
            if (preg_match(self::COMPARATOR, $part, $match) !== 1) {
                return null;
            }

            $comparators[] = [$match[1], $match[2]];
        }

        return $comparators === [] ? null : new self($comparators);
    }

    public static function isValid(string $range): bool
    {
        return self::parse($range) !== null;
    }

    public function contains(string $version): bool
    {
        $version = self::base($version);

        foreach ($this->comparators as [$operator, $limit]) {
            if (! version_compare($version, $limit, $operator === '=' ? '==' : $operator)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $candidate is newer than $installed for an upgrade. An
     * administrator's edit sorts before the version it was made from
     * (docs/adr/0018 §2), but is newer than an installation of that very
     * version: 1.1.0-local.1 is newer than 1.1.0.
     */
    public static function isNewer(string $candidate, string $installed): bool
    {
        if ($candidate !== $installed && self::base($candidate) === $installed) {
            return true;
        }

        return version_compare($candidate, $installed, '>');
    }

    /**
     * The released version an administrator's edit was made from: 1.1.0-local.2 → 1.1.0.
     */
    public static function base(string $version): string
    {
        return (string) preg_replace('/-local\.\d+$/', '', $version);
    }
}
