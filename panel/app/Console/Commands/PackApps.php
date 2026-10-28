<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\AppPackages;
use App\Domain\Apps\PackageDirectory;
use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

#[Signature('apps:pack
    {--path= : The recipe sources, one <slug>/ directory each}
    {--out= : The recipes repository the packages go to}')]
#[Description('Build <out>/<slug>/<slug>.proxapp from every recipe source of --path (docs/adr/0042)')]
class PackApps extends Command
{
    public function handle(AppPackages $packages): int
    {
        $path = $this->option('path');
        $out = $this->option('out');

        if (! is_string($path) || $path === '' || ! is_string($out) || $out === '') {
            $this->components->error('Give the recipe sources with --path and the recipes repository with --out.');

            return self::FAILURE;
        }

        $sources = RecipeLoader::resolve($path);
        $directory = new PackageDirectory(RecipeLoader::resolve($out));
        $slugs = (new RecipeLoader($sources))->slugs();
        $failed = [];

        if ($slugs === []) {
            $this->components->error("No recipes found in {$sources}.");

            return self::FAILURE;
        }

        foreach ($slugs as $slug) {
            try {
                $entries = $packages->entries($sources, $slug);
            } catch (RecipeInvalid $e) {
                $failed[$slug] = $e->errors;

                continue;
            }

            $file = $directory->path($slug);

            // Rewritten only when its content changed, so an unchanged app leaves no binary diff.
            if ($this->entries($file, $slug) === $entries) {
                $this->components->info(PackageDirectory::file($slug).' is up to date.');

                continue;
            }

            File::ensureDirectoryExists(dirname($file));
            file_put_contents($file, $packages->pack($sources, $slug));
            $this->components->info(PackageDirectory::file($slug));
        }

        foreach ($failed as $slug => $errors) {
            $this->components->error("{$slug} was not packed:");
            $this->components->bulletList($errors);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The entries of an existing package by path inside <slug>/, sorted; null when there is none.
     *
     * @return array<string, string>|null
     */
    private function entries(string $file, string $slug): ?array
    {
        $zip = new ZipArchive;

        if (! is_file($file) || $zip->open($file, ZipArchive::RDONLY) !== true) {
            return null;
        }

        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (! str_ends_with($name, '/')) {
                $entries[(string) preg_replace('~^'.preg_quote($slug, '~').'/~', '', $name)] = (string) $zip->getFromIndex($index);
            }
        }

        $zip->close();
        ksort($entries);

        return $entries;
    }
}
