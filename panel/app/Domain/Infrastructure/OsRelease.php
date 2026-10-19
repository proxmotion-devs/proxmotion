<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * The fields of /etc/os-release the panel compares with an OS template
 * (docs/adr/0024): ID, ID_LIKE, VERSION_ID and PRETTY_NAME. The file is a
 * list of shell-style assignments; values may be quoted.
 */
final readonly class OsRelease
{
    /**
     * @param  list<string>  $idLike
     */
    public function __construct(
        public string $id,
        public array $idLike,
        public ?string $versionId,
        public ?string $prettyName,
    ) {}

    public static function parse(string $content): self
    {
        $values = [];

        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)=(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $values[$match[1]] = self::unquote(trim($match[2]));
        }

        $text = fn (string $key): ?string => ($values[$key] ?? '') === '' ? null : mb_substr($values[$key], 0, 200);

        return new self(
            // os-release(5): ID defaults to "linux" when it is not set.
            id: strtolower($text('ID') ?? 'linux'),
            idLike: array_values(array_filter(explode(' ', strtolower($text('ID_LIKE') ?? '')))),
            versionId: $text('VERSION_ID'),
            prettyName: $text('PRETTY_NAME'),
        );
    }

    /** A value without its quotes; backslash escapes are undone inside double quotes. */
    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return (string) preg_replace('/\\\\([\\\\"$`])/', '$1', substr($value, 1, -1));
        }

        return $value;
    }

    /**
     * @return array{id: string, id_like: list<string>, version_id: string|null, pretty_name: string|null}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'id_like' => $this->idLike, 'version_id' => $this->versionId, 'pretty_name' => $this->prettyName];
    }
}
