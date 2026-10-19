<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Infrastructure\TemplateNameGuess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OsTemplateRequest;
use App\Models\OsTemplate;
use App\Models\ProxmoxTemplate;
use Illuminate\Http\RedirectResponse;
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

        return Inertia::render('admin/os-templates/Index', [
            'templates' => OsTemplate::query()
                ->with('proxmoxTemplates:id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (OsTemplate $template) => [
                    'uuid' => $template->uuid,
                    'name' => $template->name,
                    'family' => $template->family,
                    'version' => $template->version,
                    'distribution' => $template->distribution,
                    'distribution_version' => $template->distribution_version,
                    'platform' => $template->platform->value,
                    'is_active' => $template->is_active,
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
                ->map(fn ($group, string $name) => [
                    'name' => $name,
                    'proxmox_template_ids' => $group->pluck('id')->values(),
                    'places' => $group->map(fn (ProxmoxTemplate $template) => "{$template->cluster->name} / {$template->node->name} #{$template->vmid}")->values(),
                    'guess' => TemplateNameGuess::fromName($group->first()?->name),
                ])
                ->values(),
            'families' => OsTemplateRequest::FAMILIES,
        ]);
    }

    public function store(OsTemplateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $template = OsTemplate::create($request->templateData());
            $template->proxmoxTemplates()->sync($request->array('proxmox_template_ids'));
        });

        $this->toast('success', 'OS template created.');

        return to_route('admin.os-templates.index');
    }

    public function update(OsTemplateRequest $request, OsTemplate $osTemplate): RedirectResponse
    {
        DB::transaction(function () use ($request, $osTemplate) {
            $osTemplate->update($request->templateData());
            $osTemplate->proxmoxTemplates()->sync($request->array('proxmox_template_ids'));
        });

        $this->toast('success', 'OS template updated.');

        return to_route('admin.os-templates.index');
    }

    public function destroy(OsTemplate $osTemplate): RedirectResponse
    {
        $osTemplate->delete();

        $this->toast('success', 'OS template deleted.');

        return to_route('admin.os-templates.index');
    }
}
