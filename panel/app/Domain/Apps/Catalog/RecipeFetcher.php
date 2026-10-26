<?php

declare(strict_types=1);

namespace App\Domain\Apps\Catalog;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads a source into a directory (docs/adr/0041). A repository is fetched at
 * one commit with a shallow clone into storage; a local directory is used as it
 * is. Only https:// and ssh:// addresses are fetched: file:// and ext:: would
 * read the server's disk or run a helper program.
 */
final readonly class RecipeFetcher
{
    private const REPOSITORY = '~^(https://|ssh://|git@)[^\s]+$~';

    private const REF = '~^[A-Za-z0-9][A-Za-z0-9._/-]{0,119}$~';

    /**
     * @return array{directory: string, commit: ?string}
     *
     * @throws RecipeFetchFailed
     */
    public function fetch(RecipeSource $source): array
    {
        if ($source->path !== null) {
            if (! is_dir($source->path)) {
                throw new RecipeFetchFailed("The recipes directory {$source->path} does not exist.");
            }

            return ['directory' => $source->path, 'commit' => null];
        }

        if (preg_match(self::REPOSITORY, $source->repository) !== 1) {
            throw new RecipeFetchFailed('The recipe repository must be an https:// or ssh:// address.');
        }

        if (preg_match(self::REF, $source->ref) !== 1 || str_contains($source->ref, '..')) {
            throw new RecipeFetchFailed('The recipe repository ref is not valid.');
        }

        $directory = storage_path('app/recipe-sources/'.$source->name);
        File::deleteDirectory($directory);
        File::ensureDirectoryExists($directory);

        try {
            foreach ([
                ['init', '--quiet'],
                ['fetch', '--quiet', '--depth', '1', '--no-tags', $source->repository, $source->ref],
                ['checkout', '--quiet', '--detach', 'FETCH_HEAD'],
            ] as $arguments) {
                $result = $this->git($directory, $arguments);

                if (! $result->successful()) {
                    throw new RecipeFetchFailed('git '.$arguments[0].' failed: '.trim($result->errorOutput()));
                }
            }

            return ['directory' => $directory, 'commit' => trim($this->git($directory, ['rev-parse', 'HEAD'])->output())];
        } catch (RecipeFetchFailed $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // git could not be started at all, e.g. a web server whose temporary directory cannot be written.
            throw new RecipeFetchFailed('git could not be run: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(string $directory, array $arguments): ProcessResult
    {
        return Process::path($directory)
            ->env(['GIT_TERMINAL_PROMPT' => '0', 'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes'])
            ->timeout(180)
            ->run(['git', ...$arguments]);
    }
}
