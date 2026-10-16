<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GuestScriptRequest;
use App\Models\AuditLog;
use App\Models\GuestScript;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The scripts an OS template runs inside its servers (docs/adr/0010): the
 * defaults, the template's own versions, and a trial run on one server.
 * Every change is written to the audit log with the old and new content.
 */
class GuestScriptController extends Controller
{
    public function index(OsTemplate $osTemplate): Response
    {
        $own = $osTemplate->guestScripts()->get()->keyBy(fn (GuestScript $script) => $script->event->value);

        return Inertia::render('admin/os-templates/Scripts', [
            'template' => [
                'uuid' => $osTemplate->uuid,
                'name' => $osTemplate->name,
                'platform' => $osTemplate->platform->value,
            ],
            'scripts' => array_map(fn (GuestScriptEvent $event) => [
                'event' => $event->value,
                'content' => $own->get($event->value)?->content,
                'default' => GuestScripts::defaultScript($osTemplate->platform, $event),
                'inputs' => ['server', 'hostname', 'mac', ...$event->inputKeys()],
                'can_be_tried' => $event->canBeTried(),
                'critical' => $event->isCritical(),
            ], GuestScriptEvent::cases()),
            'servers' => $osTemplate->servers()
                ->where('status', ServerStatus::Active)
                ->orderBy('hostname')
                ->limit(200)
                ->get(['uuid', 'hostname'])
                ->map(fn (Server $server) => ['value' => $server->uuid, 'label' => $server->hostname]),
            'maxBytes' => GuestScripts::MAX_SCRIPT_BYTES,
        ]);
    }

    public function update(GuestScriptRequest $request, OsTemplate $osTemplate, GuestScriptEvent $event, #[CurrentUser] User $admin): RedirectResponse
    {
        $script = $osTemplate->guestScripts()->where('event', $event->value)->first() ?? new GuestScript;
        $before = $script->exists ? $script->content : null;

        $script->forceFill(['os_template_id' => $osTemplate->id, 'event' => $event, 'content' => $request->script()])->save();

        AuditLog::record('guest_script.updated', $admin, $osTemplate, [
            'event' => $event->value,
            'before' => $before,
            'after' => $script->content,
        ], $request->ip());

        $this->toast('success', 'Script saved.');

        return back();
    }

    public function destroy(Request $request, OsTemplate $osTemplate, GuestScriptEvent $event, #[CurrentUser] User $admin): RedirectResponse
    {
        $script = $osTemplate->guestScripts()->where('event', $event->value)->first();

        if ($script !== null) {
            $script->delete();

            AuditLog::record('guest_script.reset', $admin, $osTemplate, [
                'event' => $event->value,
                'before' => $script->content,
            ], $request->ip());
        }

        $this->toast('success', 'The default script is used again.');

        return back();
    }

    public function run(Request $request, OsTemplate $osTemplate, GuestScriptEvent $event, #[CurrentUser] User $admin, TaskRunner $tasks): RedirectResponse
    {
        abort_unless($event->canBeTried(), 404);

        $validated = $request->validate([
            'server' => ['required', 'string', Rule::exists('servers', 'uuid')->where('os_template_id', $osTemplate->id)->where('status', ServerStatus::Active->value)],
        ]);

        $server = Server::query()->where('uuid', $validated['server'])->firstOrFail();

        try {
            $tasks->start($server, TaskType::GuestScript, $admin, input: ['event' => $event->value]);
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        AuditLog::record('guest_script.run', $admin, $server, [
            'event' => $event->value,
            'hostname' => $server->hostname,
        ], $request->ip());

        $this->toast('info', 'Running the script on :hostname…', ['hostname' => $server->hostname]);

        return back();
    }
}
