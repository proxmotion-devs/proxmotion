<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\RecipeLoader;
use App\Domain\Apps\RecipeVersionGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

#[Signature('apps:check-versions
    {--base=origin/main : The git ref to compare panel/recipes with}
    {--base-path= : A recipes directory to compare with instead of a git ref}
    {--path= : The recipes directory to check (default panel/recipes)}')]
#[Description('Fail when a bundled recipe changed without a higher version (apps:sync refuses those)')]
class CheckAppVersions extends Command
{
    public function handle(RecipeVersionGuard $guard): int
    {
        $headPath = $this->option('path');
        $head = new RecipeLoader(is_string($headPath) && $headPath !== '' ? $headPath : null);
        $path = $this->option('base-path');
        $temporary = null;

        if (! is_string($path) || $path === '') {
            $temporary = storage_path('framework/recipes/base-'.Str::uuid()->toString());
            $ref = $this->option('base');
            $path = $this->checkout(is_string($ref) && $ref !== '' ? $ref : 'origin/main', $temporary);

            if ($path === null) {
                File::deleteDirectory($temporary);

                return self::FAILURE;
            }
        }

        try {
            $problems = $guard->problems(new RecipeLoader($path), $head);
        } finally {
            if ($temporary !== null) {
                File::deleteDirectory($temporary);
            }
        }

        if ($problems !== []) {
            $this->components->error('Recipes changed without a new version:');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $this->components->info('Every changed recipe has a new version.');

        return self::SUCCESS;
    }

    /**
     * Check out panel/recipes as it is at $ref into $target and return the
     * recipes directory there, or null. git writes the files as a checkout
     * would (line endings from .gitattributes), so they compare with the
     * working tree; a separate index file leaves the repository's own alone.
     */
    private function checkout(string $ref, string $target): ?string
    {
        $git = fn (array $command, array $env = []) => Process::path(base_path())->env($env)->run(['git', ...$command]);

        $root = $git(['rev-parse', '--show-toplevel']);
        $prefix = $git(['rev-parse', '--show-prefix']);

        if (! $root->successful() || ! $prefix->successful()) {
            $this->components->error('panel/ is not inside a git repository: '.trim($root->errorOutput()));

            return null;
        }

        $recipes = trim($prefix->output()).'recipes';
        File::ensureDirectoryExists($target);
        $checkout = Process::path(trim($root->output()))
            ->env(['GIT_INDEX_FILE' => $target.'/.index'])
            ->run(['git', '--work-tree='.$target, 'checkout', $ref, '--', $recipes]);

        if (! $checkout->successful()) {
            $this->components->error("The recipes could not be read at {$ref}: ".trim($checkout->errorOutput()));

            return null;
        }

        return $target.'/'.$recipes;
    }
}
