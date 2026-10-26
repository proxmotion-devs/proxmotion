<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\AppPackages;
use App\Domain\Apps\PackageRefused;
use App\Domain\Apps\Recipe;
use App\Domain\Apps\RecipeArchive;
use App\Http\Controllers\Controller;
use App\Models\AppRecipe;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * App packages (docs/adr/0042): an administrator uploads a .proxapp, sees what it
 * would change, and installs it. The validated package waits in the cache, for the
 * administrator who uploaded it only, until it is installed or expires.
 */
class AppPackageController extends Controller
{
    private const MINUTES = 30;

    public function store(Request $request, AppPackages $packages, #[CurrentUser] User $admin): RedirectResponse
    {
        $request->validate([
            'package' => ['required', 'file', 'max:'.RecipeArchive::MAX_ZIP_KIB, 'extensions:'.AppPackages::EXTENSION],
        ]);

        $package = $packages->read($request->file('package')->getRealPath());
        $token = Str::uuid()->toString();
        Cache::put(self::key($admin, $token), $package, now()->addMinutes(self::MINUTES));

        return to_route('admin.apps.packages.show', $token);
    }

    public function show(string $package, AppPackages $packages, #[CurrentUser] User $admin): Response
    {
        $data = $this->cached($admin, $package);
        $recipe = Recipe::fromArray($data['definition']);
        ['status' => $status, 'current' => $current] = $packages->status($data);

        return Inertia::render('admin/apps/Package', [
            'token' => $package,
            'package' => [
                'slug' => $data['slug'],
                'name' => $recipe->name,
                'version' => $recipe->version,
                'category' => $recipe->category,
                'platform' => $recipe->platform,
                'description' => $recipe->description,
                'files' => array_keys($data['files']),
            ],
            'status' => $status,
            'current' => $current,
            'diff' => $packages->diff($data),
        ]);
    }

    public function install(Request $request, string $package, AppPackages $packages, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $this->cached($admin, $package);

        try {
            $result = $packages->install($data, $admin, $request->ip());
        } catch (PackageRefused $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        Cache::forget(self::key($admin, $package));
        $this->toast($result === 'held' ? 'info' : 'success', match ($result) {
            'created' => 'App installed from the package.',
            'updated' => 'App updated from the package.',
            'held' => 'Saved for later: an administrator edited this app in place. Compare the new version in the editor.',
            default => 'This version is installed already.',
        });

        return to_route('admin.apps.index');
    }

    /**
     * Remove an app installed from a package (not one with installations: deactivate it instead).
     */
    public function remove(Request $request, AppRecipe $appRecipe, AppPackages $packages, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            $packages->remove($appRecipe, $admin, $request->ip());
        } catch (PackageRefused $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast('success', 'App removed.');

        return back();
    }

    /**
     * @return array{slug: string, definition: array<string, mixed>, files: array<string, string>}
     */
    private function cached(User $admin, string $token): array
    {
        $data = Cache::get(self::key($admin, $token));
        abort_unless(is_array($data), 404);

        /** @var array{slug: string, definition: array<string, mixed>, files: array<string, string>} $data */
        return $data;
    }

    private static function key(User $admin, string $token): string
    {
        return 'app-package:'.$admin->id.':'.$token;
    }
}
