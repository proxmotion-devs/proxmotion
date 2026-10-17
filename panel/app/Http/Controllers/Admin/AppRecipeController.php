<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\RecipeEditor;
use App\Domain\Apps\RecipeEditRefused;
use App\Domain\Apps\RecipeInvalid;
use App\Enums\AppRecipeVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\AppRecipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The admin recipe editor (docs/adr/0018): new custom apps, copies, the
 * draft of a custom app, publishing and deleting. Bundled apps are
 * read-only; every change is audited by RecipeEditor.
 */
class AppRecipeController extends Controller
{
    public function __construct(private readonly RecipeEditor $editor) {}

    public function store(Request $request, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string'],
            'name' => ['required', 'string'],
        ]);

        $this->editor->create($data['slug'], $data['name'], $admin, $request->ip());
        $this->toast('success', 'App created as a draft.');

        return back();
    }

    /**
     * Copy a version (the published one by default) into a new custom app.
     */
    public function copy(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string'],
            'name' => ['required', 'string'],
            'version' => ['nullable', 'string', Rule::exists('app_recipe_versions', 'version')->where('app_recipe_id', $appRecipe->id)],
        ]);

        $source = isset($data['version'])
            ? $appRecipe->versions()->where('version', $data['version'])->where('status', '!=', AppRecipeVersionStatus::Draft)->first()
            : $appRecipe->latestVersion;

        if ($source === null) {
            throw ValidationException::withMessages(['version' => __('This version cannot be copied.')]);
        }

        $this->editor->copy($source, $data['slug'], $data['name'], $admin, $request->ip());
        $this->toast('success', 'App copied as a draft.');

        return back();
    }

    /**
     * Save the draft: recipe.yaml as YAML text or as a mapping, and its files by path.
     */
    public function saveDraft(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate([
            'yaml' => ['required_without:definition', 'nullable', 'string', 'max:65536'],
            'definition' => ['required_without:yaml', 'nullable', 'array'],
            'files' => ['present', 'array'],
            'files.*' => ['nullable', 'string', 'max:46080'],
        ]);

        $definition = $data['definition'] ?? null;

        if (isset($data['yaml'])) {
            try {
                $definition = Yaml::parse($data['yaml']);
            } catch (ParseException $e) {
                throw ValidationException::withMessages(['yaml' => __('recipe.yaml is not valid YAML: :error', ['error' => $e->getMessage()])]);
            }
        }

        if (! is_array($definition)) {
            throw ValidationException::withMessages(['yaml' => __('recipe.yaml must be a mapping.')]);
        }

        /** @var array<string, mixed> $definition */
        $this->refused(fn () => $this->editor->saveDraft($appRecipe, $definition, $data['files'], $admin, $request->ip()));
        $this->toast('success', 'Draft saved.');

        return back();
    }

    public function discardDraft(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $this->refused(fn () => $this->editor->discardDraft($appRecipe, $admin, $request->ip()));
        $this->toast('success', 'Draft discarded.');

        return back();
    }

    /**
     * Publish the draft as the next patch, minor or major version.
     */
    public function publish(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate(['bump' => ['required', Rule::in(['patch', 'minor', 'major'])]]);

        try {
            $version = $this->refused(fn () => $this->editor->publish($appRecipe, $data['bump'], $admin, $request->ip()));
        } catch (RecipeInvalid $e) {
            throw ValidationException::withMessages(['draft' => $e->errors]);
        }

        $this->toast('success', 'Version :version published.', ['version' => $version->version]);

        return back();
    }

    public function destroy(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $this->refused(fn () => $this->editor->delete($appRecipe, $admin, $request->ip()));
        $this->toast('success', 'App deleted.');

        return to_route('admin.apps.index');
    }

    /**
     * Run an editor operation; a refusal becomes a form error.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function refused(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RecipeEditRefused $e) {
            throw ValidationException::withMessages(['app' => $e->translatedMessage()]);
        }
    }
}
