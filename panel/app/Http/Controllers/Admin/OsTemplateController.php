<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
                    'platform' => $template->platform->value,
                    'is_active' => $template->is_active,
                    'sort_order' => $template->sort_order,
                    'proxmox_template_ids' => $template->proxmoxTemplates->pluck('id'),
                ]),
            'proxmoxTemplates' => ProxmoxTemplate::query()
                ->with(['cluster:id,name', 'node:id,name'])
                ->orderBy('cluster_id')
                ->orderBy('vmid')
                ->get()
                ->map(fn (ProxmoxTemplate $template) => [
                    'id' => $template->id,
                    'vmid' => $template->vmid,
                    'name' => $template->name,
                    'cluster' => $template->cluster->name,
                    'node' => $template->node->name,
                ]),
            'families' => OsTemplateRequest::FAMILIES,
        ]);
    }

    public function store(OsTemplateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $template = OsTemplate::create($request->safe()->except('proxmox_template_ids'));
            $template->proxmoxTemplates()->sync($request->array('proxmox_template_ids'));
        });

        $this->toast('success', 'OS template created.');

        return to_route('admin.os-templates.index');
    }

    public function update(OsTemplateRequest $request, OsTemplate $osTemplate): RedirectResponse
    {
        DB::transaction(function () use ($request, $osTemplate) {
            $osTemplate->update($request->safe()->except('proxmox_template_ids'));
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
