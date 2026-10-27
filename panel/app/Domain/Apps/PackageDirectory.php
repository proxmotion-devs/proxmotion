<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * A recipes repository of packages (docs/adr/0042): one folder per app,
 * holding only its package, <slug>/<slug>.proxapp. Folders starting with
 * "." or "_" are not apps (.github, unpacked copies in CI).
 */
final readonly class PackageDirectory
{
    public function __construct(public string $directory) {}

    /**
     * The app folders, sorted.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        $slugs = [];

        foreach (glob($this->directory.'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);

            if (! str_starts_with($slug, '.') && ! str_starts_with($slug, '_')) {
                $slugs[] = $slug;
            }
        }

        sort($slugs);

        return $slugs;
    }

    public static function file(string $slug): string
    {
        return $slug.'/'.$slug.'.'.AppPackages::EXTENSION;
    }

    public function path(string $slug): string
    {
        return $this->directory.'/'.self::file($slug);
    }

    /**
     * What is wrong with one app folder: no package, anything besides it.
     *
     * @return list<string>
     */
    public function problems(string $slug): array
    {
        $problems = [];
        $package = basename(self::file($slug));

        foreach (scandir($this->directory.'/'.$slug) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && $name !== $package) {
                $problems[] = "{$slug}/{$name} does not belong here; an app folder holds only {$package}.";
            }
        }

        if (! is_file($this->path($slug)) || is_link($this->path($slug))) {
            array_unshift($problems, "{$slug}/{$package} is missing.");
        }

        return $problems;
    }
}
