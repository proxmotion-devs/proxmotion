<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperatingSystemRequest;
use App\Http\Requests\Admin\OperatingSystemVersionRequest;
use App\Models\OperatingSystem;
use App\Models\OperatingSystemVersion;
use App\Models\OsTemplate;
use Illuminate\Http\RedirectResponse;

/**
 * The administrators' operating systems and their versions, managed on the
 * OS templates page (docs/adr/0026). A system or version OS templates use
 * cannot be deleted.
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

    public function destroy(OperatingSystem $operatingSystem): RedirectResponse
    {
        $templates = OsTemplate::query()->whereIn('operating_system_version_id', $operatingSystem->versions()->select('id'))->count();

        if ($templates > 0) {
            $this->toast('error', ':count OS templates use :name. Change them first.', ['count' => (string) $templates, 'name' => $operatingSystem->name]);

            return back();
        }

        $operatingSystem->delete();

        $this->toast('success', 'Operating system deleted.');

        return to_route('admin.os-templates.index');
    }

    public function storeVersion(OperatingSystemVersionRequest $request, OperatingSystem $operatingSystem): RedirectResponse
    {
        $operatingSystem->versions()->create(['version' => (string) $request->validated('version')]);

        $this->toast('success', 'Version added. Tick it on the apps that run on it.');

        return to_route('admin.os-templates.index');
    }

    public function destroyVersion(OperatingSystem $operatingSystem, OperatingSystemVersion $version): RedirectResponse
    {
        $templates = $version->osTemplates()->count();

        if ($templates > 0) {
            $this->toast('error', ':count OS templates use :name. Change them first.', ['count' => (string) $templates, 'name' => $version->label()]);

            return back();
        }

        $version->delete();

        $this->toast('success', 'Version deleted.');

        return to_route('admin.os-templates.index');
    }
}
