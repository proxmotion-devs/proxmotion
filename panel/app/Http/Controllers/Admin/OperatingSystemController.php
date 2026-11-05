<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperatingSystemRequest;
use App\Http\Requests\Admin\OperatingSystemVersionRequest;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\OperatingSystem;
use App\Models\OperatingSystemVersion;
use App\Models\OsTemplate;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The administrators' operating systems and their versions, managed on the
 * OS templates page (docs/adr/0026). A system or version OS templates use
 * cannot be deleted. Deleting one also takes it off the apps ticked for it,
 * so the deletion is audited with those apps.
 */
class OperatingSystemController extends Controller
{
    public function store(OperatingSystemRequest $request): RedirectResponse
    {
        OperatingSystem::query()->create([
            ...$request->safe()->only(['name', 'platform']),
            'sort_order' => (int) OperatingSystem::query()->max('sort_order') + 1,
        ]);

        $this->toast('success', 'Operating system added.');

        return to_route('admin.os-templates.index');
    }

    public function update(OperatingSystemRequest $request, OperatingSystem $operatingSystem): RedirectResponse
    {
        $operatingSystem->update($request->safe()->only(['name', 'platform']));

        $this->toast('success', 'Operating system updated.');

        return to_route('admin.os-templates.index');
    }

    public function destroy(Request $request, OperatingSystem $operatingSystem, #[CurrentUser] User $admin): RedirectResponse
    {
        $templates = OsTemplate::query()->whereIn('operating_system_version_id', $operatingSystem->versions()->select('id'))->count();

        if ($templates > 0) {
            $this->toast('error', ':count OS templates use :name. Change them first.', ['count' => (string) $templates, 'name' => $operatingSystem->name]);

            return back();
        }

        $apps = AppRecipe::query()->whereHas('osVersions', fn ($query) => $query->where('operating_system_id', $operatingSystem->id))->orderBy('slug')->pluck('slug')->all();

        $operatingSystem->delete();

        // Its versions go with it, and so do the apps' ticks for them.
        AuditLog::record('operating_system.deleted', $admin, $operatingSystem, [
            'name' => $operatingSystem->name,
            'apps' => $apps,
        ], $request->ip());

        $this->toast('success', 'Operating system deleted.');

        return to_route('admin.os-templates.index');
    }

    public function storeVersion(OperatingSystemVersionRequest $request, OperatingSystem $operatingSystem): RedirectResponse
    {
        $operatingSystem->versions()->create(['version' => (string) $request->validated('version')]);

        $this->toast('success', 'Version added. Tick it on the apps that run on it.');

        return to_route('admin.os-templates.index');
    }

    public function destroyVersion(Request $request, OperatingSystem $operatingSystem, OperatingSystemVersion $version, #[CurrentUser] User $admin): RedirectResponse
    {
        $templates = $version->osTemplates()->count();

        if ($templates > 0) {
            $this->toast('error', ':count OS templates use :name. Change them first.', ['count' => (string) $templates, 'name' => $version->label()]);

            return back();
        }

        $apps = $version->apps()->orderBy('slug')->pluck('slug')->all();

        $version->delete();

        // The apps ticked for it are no longer offered on it.
        AuditLog::record('operating_system.version_deleted', $admin, $operatingSystem, [
            'version' => $version->label(),
            'apps' => $apps,
        ], $request->ip());

        $this->toast('success', 'Version deleted.');

        return to_route('admin.os-templates.index');
    }
}
