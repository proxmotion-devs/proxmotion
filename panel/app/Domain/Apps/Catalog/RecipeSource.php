<?php

declare(strict_types=1);

namespace App\Domain\Apps\Catalog;

/**
 * Where app recipes come from (docs/adr/0041): a git repository read at a ref,
 * or a local directory that is read as it is (PROXMOTION_RECIPES_PATH).
 */
final readonly class RecipeSource
{
    public const OFFICIAL = 'official';

    public function __construct(
        public string $name,
        public string $repository,
        public string $ref,
        public ?string $path = null,
    ) {}

    /**
     * The project's recipe repository, or the local directory configured instead.
     */
    public static function official(): self
    {
        $path = config('proxmotion.recipes.path');

        return new self(
            self::OFFICIAL,
            (string) config('proxmotion.recipes.repository'),
            (string) config('proxmotion.recipes.ref'),
            is_string($path) && $path !== '' ? self::directory($path) : null,
        );
    }

    /**
     * A recipes directory as given on the command line or in the configuration: relative paths are relative to panel/.
     */
    public static function directory(string $path): string
    {
        $absolute = $path[0] === '/' || preg_match('~^[A-Za-z]:~', $path) === 1;

        return $absolute ? $path : base_path($path);
    }
}
