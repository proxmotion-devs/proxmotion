<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\AppSystems;
use App\Domain\Infrastructure\OsReleaseCheck;
use App\Domain\Infrastructure\OsReleaseUnavailable;
use App\Domain\Infrastructure\TemplateNameGuess;
use App\Enums\OsPlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OsTemplateRequest;
use App\Models\AuditLog;
use App\Models\OperatingSystem;
use App\Models\OperatingSystemVersion;
use App\Models\OsTemplate;
use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OsTemplateController extends Controller
{
    public function index(): Response
    {
        $proxmoxTemplates = ProxmoxTemplate::query()
            ->with(['cluster:id,name', 'node:id,name'])
            ->withCount('osTemplates')
            ->orderBy('cluster_id')
            ->orderBy('vmid')
            ->get();
        $versions = AppSystems::all()->loadCount('osTemplates');

        return Inertia::render('admin/os-templates/Index', [
            'templates' => OsTemplate::query()
                ->with('proxmoxTemplates:id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (OsTemplate $template) => [
                    'uuid' => $template->uuid,
                    'name' => $template->name,
                    'operating_system_version_id' => $template->operating_system_version_id,
                    'distribution' => $template->distribution,
                    'version' => $template->version,
                    'platform' => $template->platform->value,
                    'is_active' => $template->is_active,
                    'clone_mode' => $template->clone_mode->value,
                    'sort_order' => $template->sort_order,
                    'proxmox_template_ids' => $template->proxmoxTemplates->pluck('id'),
                ]),
            'proxmoxTemplates' => $proxmoxTemplates->map(fn (ProxmoxTemplate $template) => [
                'id' => $template->id,
                'vmid' => $template->vmid,
                'name' => $template->name,
                'cluster' => $template->cluster->name,
                'node' => $template->node->name,
            ])->values(),
            // Templates no OS template uses yet, one entry per name (the same
            // image on several nodes or clusters), with a guess to start from.
            'found' => $proxmoxTemplates->filter(fn (ProxmoxTemplate $template) => $template->os_templates_count === 0)
                ->groupBy(fn (ProxmoxTemplate $template) => $template->name ?? "#{$template->vmid}")
                ->map(function ($group, string $name) use ($versions) {
                    $guess = TemplateNameGuess::fromName($group->first()?->name);

                    return [
                        'name' => $name,
                        'proxmox_template_ids' => $group->pluck('id')->values(),
                        'places' => $group->map(fn (ProxmoxTemplate $template) => "{$template->cluster->name} / {$template->node->name} #{$template->vmid}")->values(),
                        'guess' => ['name' => $guess['name'], 'operating_system_version_id' => self::guessedVersion($guess, $versions)],
                    ];
                })
                ->values(),
            // The administrators' operating systems and versions (docs/adr/0026).
            'systems' => OperatingSystem::query()->orderBy('sort_order')->orderBy('name')->get()->map(fn (OperatingSystem $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'platform' => $system->platform->value,
                'versions' => $versions->where('operating_system_id', $system->id)->map(fn (OperatingSystemVersion $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'templates' => (int) $version->getAttribute('os_templates_count'),
                ])->values(),
            ]),
        ]);
    }

    /**
     * The listed version a template name points to: the system the guess
     * names, and the same version or the one it is a point release of.
     *
     * @param  array{name: string, distribution: string, version: string}  $guess
     * @param  Collection<int, OperatingSystemVersion>  $versions
     */
    private static function guessedVersion(array $guess, Collection $versions): ?int
    {
        if ($guess['distribution'] === '') {
            return null;
        }

        $match = $versions->first(fn (OperatingSystemVersion $version) => AppSystems::osReleaseId($version->operatingSystem->slug, $version->operatingSystem->name) === $guess['distribution']
            && ($guess['version'] === $version->version || str_starts_with($guess['version'], $version->version.'.')));

        return $match?->id;
    }

    /**
     * /etc/os-release of a running server of the template, compared with it
     * (docs/adr/0024 §8). Only reads: the administrator saves the suggestion
     * through the edit form.
     */
    public function osRelease(Request $request, OsTemplate $osTemplate, OsReleaseCheck $check): JsonResponse
    {
        abort_if($osTemplate->platform === OsPlatform::Windows, 422, __('Windows has no /etc/os-release.'));

        $servers = $check->servers($osTemplate);
        $wanted = $request->query('server');
        $server = is_string($wanted) ? $servers->firstWhere('uuid', $wanted) : $servers->first();

        if ($server === null) {
            return response()->json(['message' => __('No running server uses this template yet. Create one from it, for example a test server, and check again.'), 'no_server' => true], 422);
        }

        try {
            $release = $check->read($server);
        } catch (OsReleaseUnavailable $e) {
            return response()->json(['message' => $e->translatedMessage(), 'server' => $server->uuid, 'servers' => self::serverOptions($servers)], 422);
        }

        return response()->json([
            'server' => $server->uuid,
            'servers' => self::serverOptions($servers),
            'os_release' => $release->toArray(),
            ...$check->compare($osTemplate, $release),
        ]);
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @return list<array{value: string, label: string}>
     */
    private static function serverOptions(Collection $servers): array
    {
        return array_values($servers->map(fn (Server $server) => [
            'value' => $server->uuid,
            'label' => $server->is_test ? "{$server->hostname} (".__('test server').')' : $server->hostname,
        ])->all());
    }

    public function store(OsTemplateRequest $request): RedirectResponse
    {
        $template = DB::transaction(function () use ($request) {
            $template = OsTemplate::create($request->templateData());
            $template->proxmoxTemplates()->sync($request->array('proxmox_template_ids'));

            return $template;
        });

        // What to do next: a Linux template is confirmed against a server built from it (docs/adr/0024 §8).
        $this->toast('success', $template->platform === OsPlatform::Linux
            ? 'OS template created. Create a server from it, then use Check against a server to confirm the system.'
            : 'OS template created.');

        return to_route('admin.os-templates.index');
    }

    public function update(OsTemplateRequest $request, OsTemplate $osTemplate, #[CurrentUser] User $admin): RedirectResponse
    {
        $cloneMode = $osTemplate->clone_mode;

        DB::transaction(function () use ($request, $osTemplate) {
            $osTemplate->update($request->templateData());
            $osTemplate->proxmoxTemplates()->sync($request->array('proxmox_template_ids'));
        });

        // How new servers are cloned (docs/adr/0027).
        if ($osTemplate->clone_mode !== $cloneMode) {
            AuditLog::record('os_template.clone_mode_changed', $admin, $osTemplate, [
                'name' => $osTemplate->name,
                'before' => $cloneMode->value,
                'after' => $osTemplate->clone_mode->value,
            ], $request->ip());
        }

        $this->toast('success', 'OS template updated.');

        return to_route('admin.os-templates.index');
    }

    public function destroy(OsTemplate $osTemplate): RedirectResponse
    {
        // Packages would silently allow every template (docs/adr/0040).
        $packages = $osTemplate->packages()->count();

        if ($packages > 0) {
            $this->toast('error', ':count packages use this OS template. Remove it from them first.', ['count' => (string) $packages]);

            return back();
        }

        $osTemplate->delete();

        $this->toast('success', 'OS template deleted.');

        return to_route('admin.os-templates.index');
    }
}
