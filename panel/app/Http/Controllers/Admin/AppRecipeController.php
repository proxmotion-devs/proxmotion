<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppTrials;
use App\Domain\Apps\LiveAppPolls;
use App\Domain\Apps\OsFamilies;
use App\Domain\Apps\RecipeArchive;
use App\Domain\Apps\RecipeEditor;
use App\Domain\Apps\RecipeEditRefused;
use App\Domain\Apps\RecipeInput;
use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeLoader;
use App\Enums\AppRecipeVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OsTemplateRequest;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The admin recipe editor (docs/adr/0018): new custom apps, copies, the
 * draft of any app (bundled apps are edited in place), publishing, going
 * back to the bundled recipe and deleting custom apps; every change is
 * audited by RecipeEditor.
 */
class AppRecipeController extends Controller
{
    public function __construct(private readonly RecipeEditor $editor) {}

    /**
     * The editor: the draft (or the published version to start one from),
     * the versions and what the forms offer.
     */
    public function edit(Request $request, AppRecipe $appRecipe, RecipeLoader $loader, AppTrials $trials, LiveAppPolls $live): Response
    {
        // The page's refreshes while a trial runs ask its server, so progress shows live (docs/adr/0021).
        if (in_array('trials', explode(',', (string) $request->header('X-Inertia-Partial-Data')), true)) {
            $trials->pollRunning($appRecipe, $live);
        }

        $appRecipe->load(['latestVersion', 'copiedFrom.app.latestVersion']);
        $draft = $appRecipe->draft();
        $draftErrors = $draft === null ? [] : $this->editor->errors($draft);
        $published = $appRecipe->latestVersion;
        $origin = $appRecipe->copiedFrom;
        $newer = $origin?->app->latestVersion;
        $upstream = $appRecipe->isCustom() ? null : $appRecipe->latestUpstream();
        // The bundled version an edit started from: 1.2.1-local.2 → 1.2.1.
        $base = $published === null ? null : (string) preg_replace('/-local\.\d+$/', '', $published->version);

        return Inertia::render('admin/apps/Edit', [
            'app' => [
                'slug' => $appRecipe->slug,
                'name' => $appRecipe->name,
                'source' => $appRecipe->source,
                'is_active' => $appRecipe->is_active,
                'editable' => true,
                'modified' => $appRecipe->isModified(),
                // A bundled release newer than the one the administrator's edit started from (docs/adr/0018 §2).
                'bundled_version' => $upstream?->version,
                'bundled_newer' => $appRecipe->isModified() && $upstream !== null && $base !== null && version_compare($upstream->version, $base, '>'),
                'installed' => $appRecipe->inUse(),
                'copied_from' => $origin === null ? null : ['slug' => $origin->app->slug, 'name' => $origin->app->name, 'version' => $origin->version],
                // The original got a newer version since the copy (docs/adr/0018 §2).
                'upstream_version' => $newer !== null && version_compare($newer->version, $origin->version ?? '0', '>') ? $newer->version : null,
            ],
            'draft' => $draft === null ? null : [
                'version' => $draft->version,
                'definition' => $draft->definition,
                'files' => (object) $draft->files,
                'errors' => $draftErrors,
            ],
            'published' => $published === null ? null : [
                'version' => $published->version,
                'definition' => $published->definition,
                'files' => (object) $published->files,
            ],
            // Trial runs of the draft on test servers (docs/adr/0018 §7).
            'trials' => $trials->forEditor($appRecipe, $draft),
            'testServers' => $trials->testServers($appRecipe, $draft),
            'trialInputs' => $draft === null || $draftErrors !== [] ? [] : array_map(fn (RecipeInput $input) => $input->toArray(), $draft->recipe()->inputs),
            // Trial copies of drafts are listed with the trials, not here.
            'versions' => $appRecipe->versions()->where('status', '!=', AppRecipeVersionStatus::Trial)->with('reviewer:id,name')->orderByDesc('id')->get()->map(fn (AppRecipeVersion $version) => [
                'version' => $version->version,
                'status' => $version->status->value,
                'published_by' => $version->reviewer?->name,
                'published_at' => $version->reviewed_at?->toIso8601String(),
                'created_at' => $version->created_at?->toIso8601String(),
            ]),
            // requires.os takes template families and groups of them (docs/adr/0016 §3).
            'osGroups' => OsFamilies::GROUPS,
            'families' => OsTemplateRequest::FAMILIES,
            'library' => $loader->library(),
        ]);
    }

    /**
     * The diff between two versions of the app (or a version and the draft).
     */
    public function diff(Request $request, AppRecipe $appRecipe): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'string', 'max:20'], 'to' => ['required', 'string', 'max:20']]);
        $find = fn (string $version) => $version === 'draft'
            ? $appRecipe->draft()
            : $appRecipe->versions()->where('version', $version)->where('status', '!=', AppRecipeVersionStatus::Draft)->first();
        $from = $find($data['from']);
        $to = $find($data['to']);

        abort_if($from === null || $to === null, 404);

        return response()->json(['diff' => $this->editor->diffBetween($from, $to)]);
    }

    /**
     * Download a version (the published one by default, or the draft) as a
     * zip of its recipe directory (docs/adr/0018 §5). Trial copies are not offered.
     */
    public function export(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin, RecipeArchive $archive): SymfonyResponse
    {
        $data = $request->validate(['version' => ['nullable', 'string', 'max:40']]);
        $version = match ($data['version'] ?? null) {
            null => $appRecipe->latestVersion ?? $appRecipe->draft(),
            'draft' => $appRecipe->draft(),
            default => $appRecipe->versions()->where('version', $data['version'])->whereNotIn('status', [AppRecipeVersionStatus::Draft, AppRecipeVersionStatus::Trial])->first(),
        };

        abort_if($version === null, 404);
        $version->setRelation('app', $appRecipe);

        AuditLog::record('app.exported', $admin, $appRecipe, ['app' => $appRecipe->slug, 'version' => $version->version, 'draft' => $version->status === AppRecipeVersionStatus::Draft], $request->ip());

        $filename = $appRecipe->slug.'-'.($version->status === AppRecipeVersionStatus::Draft ? 'draft' : $version->version).'.zip';

        return response($archive->export($version), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ]);
    }

    /**
     * A new custom app from an uploaded recipe zip, as a draft.
     */
    public function import(Request $request, #[CurrentUser] User $admin, RecipeArchive $archive): RedirectResponse
    {
        $data = $request->validate([
            'archive' => ['required', 'file', 'max:'.RecipeArchive::MAX_ZIP_KIB],
            'slug' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
        ]);

        ['definition' => $definition, 'files' => $files] = $archive->read($request->file('archive')->getRealPath());
        $app = $this->editor->import($definition, $files, $data['slug'] ?? null, $data['name'] ?? null, $admin, $request->ip());
        $this->toast('success', 'App imported as a draft.');

        return to_route('admin.apps.edit', $app);
    }

    /**
     * Replace the app's draft with an uploaded recipe zip (its slug stays).
     */
    public function importDraft(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin, RecipeArchive $archive): RedirectResponse
    {
        $request->validate(['archive' => ['required', 'file', 'max:'.RecipeArchive::MAX_ZIP_KIB]]);

        ['definition' => $definition, 'files' => $files] = $archive->read($request->file('archive')->getRealPath());
        $this->refused(fn () => $this->editor->saveDraft($appRecipe, $definition, $files, $admin, $request->ip(), 'app.imported'));
        $this->toast('success', 'Draft replaced by the imported recipe.');

        return back();
    }

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

    /**
     * Go back from an edit of a bundled app to its newest bundled version.
     */
    public function revert(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $version = $this->refused(fn () => $this->editor->revert($appRecipe, $admin, $request->ip()));
        $this->toast('success', 'Back to the bundled version :version.', ['version' => $version->version]);

        return back();
    }

    /**
     * Try the draft as it is saved now on a test server (docs/adr/0018 §7).
     */
    public function trial(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin, AppTrials $trials): RedirectResponse
    {
        $data = $request->validate([
            'server' => ['required', 'string', Rule::exists('servers', 'uuid')->where('is_test', true)],
            'inputs' => ['nullable', 'array'],
        ]);

        $server = Server::query()->where('uuid', $data['server'])->firstOrFail();

        try {
            $this->refused(fn () => $trials->try($appRecipe, $server, $data['inputs'] ?? [], $admin, $request->ip()));
        } catch (TaskAlreadyRunning|AppInstallConflict $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['server' => $e->translatedMessage()]);
        }

        $this->toast('info', 'Trying the draft on :server…', ['server' => $server->hostname]);

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
