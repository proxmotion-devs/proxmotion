<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Infrastructure\DistributionChanges;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DistributionReleaseRequest;
use App\Http\Requests\Admin\DistributionRequest;
use App\Models\Distribution;
use App\Models\DistributionRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * The operating systems the OS template form offers, managed on the OS
 * templates page (docs/adr/0025).
 */
class DistributionController extends Controller
{
    public function store(DistributionRequest $request): RedirectResponse
    {
        Distribution::query()->create([
            ...$request->values(),
            'slug' => (string) $request->input('slug'),
            'sort_order' => (int) Distribution::query()->max('sort_order') + 1,
        ]);

        $this->toast('success', 'Operating system added.');

        return to_route('admin.os-templates.index');
    }

    public function update(DistributionRequest $request, Distribution $distribution): RedirectResponse
    {
        DB::transaction(function () use ($request, $distribution) {
            $distribution->update($request->values());
            DistributionChanges::applyTo($distribution->slug);
        });

        $this->toast('success', 'Operating system updated.');

        return to_route('admin.os-templates.index');
    }

    public function destroy(Distribution $distribution): RedirectResponse
    {
        if (DistributionChanges::inUse($distribution->slug)) {
            $this->toast('error', ':name is used by OS templates or other systems. Change them first.', ['name' => $distribution->name]);

            return back();
        }

        $distribution->delete();

        $this->toast('success', 'Operating system deleted.');

        return to_route('admin.os-templates.index');
    }

    public function storeRelease(DistributionReleaseRequest $request, Distribution $distribution): RedirectResponse
    {
        DB::transaction(function () use ($request, $distribution) {
            $distribution->releases()->create($request->safe()->only(['version', 'base_version']));
            DistributionChanges::applyTo($distribution->slug);
        });

        $this->toast('success', 'Version added.');

        return to_route('admin.os-templates.index');
    }

    /** OS templates keep their version; the form lists it until another is picked. */
    public function destroyRelease(Distribution $distribution, DistributionRelease $release): RedirectResponse
    {
        $release->delete();

        $this->toast('success', 'Version deleted.');

        return to_route('admin.os-templates.index');
    }
}
