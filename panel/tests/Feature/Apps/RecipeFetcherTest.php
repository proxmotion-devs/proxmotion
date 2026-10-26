<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\Catalog\RecipeFetcher;
use App\Domain\Apps\Catalog\RecipeFetchFailed;
use App\Domain\Apps\Catalog\RecipeSource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Reading a recipe source (docs/adr/0041): repositories are fetched over https or ssh only,
 * at a ref, shallowly; a local directory is read as it is.
 */
class RecipeFetcherTest extends TestCase
{
    private const REPOSITORY = 'https://github.com/proxmotion-devs/proxmotion-recipes.git';

    public function test_a_repository_is_fetched_shallowly_at_its_ref()
    {
        Process::fake();

        $result = app(RecipeFetcher::class)->fetch(new RecipeSource('official', self::REPOSITORY, 'main'));

        $this->assertStringEndsWith('app/recipe-sources/official', $result['directory']);
        Process::assertRan(fn ($process) => implode(' ', (array) $process->command) === 'git fetch --quiet --depth 1 --no-tags '.self::REPOSITORY.' main');
        Process::assertRan(fn ($process) => implode(' ', (array) $process->command) === 'git checkout --quiet --detach FETCH_HEAD');
    }

    public function test_a_local_directory_is_read_as_it_is()
    {
        Process::fake();

        $result = app(RecipeFetcher::class)->fetch(new RecipeSource('official', self::REPOSITORY, 'main', base_path('tests/Fixtures/recipes')));

        $this->assertSame(['directory' => base_path('tests/Fixtures/recipes'), 'commit' => null], $result);
        Process::assertNothingRan();
    }

    public function test_a_missing_local_directory_is_reported()
    {
        $this->expectException(RecipeFetchFailed::class);
        $this->expectExceptionMessage('does not exist');

        app(RecipeFetcher::class)->fetch(new RecipeSource('official', self::REPOSITORY, 'main', base_path('no-such-recipes')));
    }

    public function test_only_https_and_ssh_repositories_are_fetched()
    {
        Process::fake();

        foreach (['file:///etc', 'ext::sh -c id', '--upload-pack=touch', 'http://example.com/recipes.git'] as $repository) {
            try {
                app(RecipeFetcher::class)->fetch(new RecipeSource('official', $repository, 'main'));
                $this->fail("{$repository} was fetched.");
            } catch (RecipeFetchFailed $e) {
                $this->assertSame('The recipe repository must be an https:// or ssh:// address.', $e->getMessage());
            }
        }

        Process::assertNothingRan();
    }

    public function test_a_ref_that_is_an_option_is_refused()
    {
        Process::fake();

        $this->expectException(RecipeFetchFailed::class);
        $this->expectExceptionMessage('The recipe repository ref is not valid.');

        app(RecipeFetcher::class)->fetch(new RecipeSource('official', self::REPOSITORY, '--upload-pack=x'));
    }

    public function test_a_failing_git_step_says_what_git_reported()
    {
        Process::fake(['*fetch*' => Process::result(errorOutput: 'fatal: repository not found', exitCode: 128)]);

        $this->expectException(RecipeFetchFailed::class);
        $this->expectExceptionMessage('git fetch failed: fatal: repository not found');

        app(RecipeFetcher::class)->fetch(new RecipeSource('official', self::REPOSITORY, 'main'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/recipe-sources'));

        parent::tearDown();
    }
}
