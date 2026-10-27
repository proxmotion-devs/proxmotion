<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GuestScriptRequest;
use App\Models\AuditLog;
use App\Models\CustomScript;
use App\Models\GuestScript;
use App\Models\GuestScriptLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The scripts an OS template runs inside its servers (docs/adr/0010): the
 * defaults, the template's own versions, their drafts, trial runs on one
 * server and the log of every run (docs/adr/0023). Only published scripts
 * run during operations; a draft runs only in trials. Every change is
 * written to the audit log with the old and new content.
 */
class GuestScriptController extends Controller
{
    /** Runs listed per event; older ones stay until they are pruned. */
    private const LOGS_PER_EVENT = 8;

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
                'draft' => $own->get($event->value)?->draft_content,
                'default' => GuestScripts::defaultScript($osTemplate->platform, $event),
                'inputs' => ['server', 'hostname', 'mac', ...$event->inputKeys()],
                'can_be_tried' => $event->canBeTried(),
                'critical' => $event->isCritical(),
                'timeout_seconds' => $event->timeoutSeconds(),
            ], GuestScriptEvent::cases()),
            'logs' => fn () => $this->logs($osTemplate),
            // Administrators' own scripts and their latest runs (docs/adr/0043).
            'customScripts' => $osTemplate->customScripts()->get()->map(fn (CustomScript $script) => [
                'uuid' => $script->uuid,
                'name' => $script->name,
                'manual' => $script->manual,
                'events' => $script->events,
                'content' => $script->content,
                'draft' => $script->draft_content,
            ])->values(),
            'customLogs' => fn () => $this->customLogs($osTemplate),
            'servers' => $osTemplate->servers()
                ->where('status', ServerStatus::Active)
                ->orderBy('hostname')
                ->limit(200)
                ->get(['uuid', 'hostname'])
                ->map(fn (Server $server) => ['value' => $server->uuid, 'label' => $server->hostname]),
            'maxBytes' => GuestScripts::MAX_SCRIPT_BYTES,
            'canTrace' => $osTemplate->platform === OsPlatform::Linux,
        ]);
    }

    /**
     * Save the editor's content as the event's draft; nothing runs it yet.
     */
    public function update(GuestScriptRequest $request, OsTemplate $osTemplate, GuestScriptEvent $event, #[CurrentUser] User $admin): RedirectResponse
    {
        $script = $this->slot($osTemplate, $event) ?? new GuestScript;
        $before = $script->exists ? $script->draft_content : null;

        $script->forceFill(['os_template_id' => $osTemplate->id, 'event' => $event, 'draft_content' => $request->script()])->save();

        AuditLog::record('guest_script.draft_saved', $admin, $osTemplate, [
            'event' => $event->value,
            'before' => $before,
            'after' => $script->draft_content,
        ], $request->ip());

        $this->toast('success', 'Draft saved. Try it on a server, then publish it.');

        return back();
    }

    /**
     * Make the draft the script that runs during operations.
     */
    public function publish(Request $request, OsTemplate $osTemplate, GuestScriptEvent $event, #[CurrentUser] User $admin): RedirectResponse
    {
        $script = $this->slot($osTemplate, $event);

        if ($script === null || $script->draft_content === null) {
            $this->toast('error', 'There is no draft to publish.');

            return back();
        }

        $before = $script->content;
        $script->forceFill(['content' => $script->draft_content, 'draft_content' => null])->save();

        AuditLog::record('guest_script.updated', $admin, $osTemplate, [
            'event' => $event->value,
            'before' => $before,
            'after' => $script->content,
        ], $request->ip());

        $this->toast('success', 'Script published.');

        return back();
    }

    public function discard(Request $request, OsTemplate $osTemplate, GuestScriptEvent $event, #[CurrentUser] User $admin): RedirectResponse
    {
        $script = $this->slot($osTemplate, $event);

        if ($script !== null && $script->draft_content !== null) {
            $before = $script->draft_content;
            $script->content === null ? $script->delete() : $script->forceFill(['draft_content' => null])->save();

            AuditLog::record('guest_script.draft_discarded', $admin, $osTemplate, [
                'event' => $event->value,
                'before' => $before,
            ], $request->ip());
        }

        $this->toast('success', 'Draft discarded.');

        return back();
    }

    /**
     * Use the default again during operations; a draft is kept.
     */
    public function destroy(Request $request, OsTemplate $osTemplate, GuestScriptEvent $event, #[CurrentUser] User $admin): RedirectResponse
    {
        $script = $this->slot($osTemplate, $event);

        if ($script !== null && $script->content !== null) {
            $before = $script->content;
            $script->draft_content === null ? $script->delete() : $script->forceFill(['content' => null])->save();

            AuditLog::record('guest_script.reset', $admin, $osTemplate, [
                'event' => $event->value,
                'before' => $before,
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
            'draft' => ['sometimes', 'boolean'],
            'trace' => ['sometimes', 'boolean'],
        ]);

        $draft = $request->boolean('draft');

        if ($draft && $this->slot($osTemplate, $event)?->draft_content === null) {
            $this->toast('error', 'There is no draft to run.');

            return back();
        }

        $server = Server::query()->where('uuid', $validated['server'])->firstOrFail();
        $trace = $request->boolean('trace') && $osTemplate->platform === OsPlatform::Linux;

        try {
            $tasks->start($server, TaskType::GuestScript, $admin, input: ['event' => $event->value, 'draft' => $draft, 'trace' => $trace]);
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        AuditLog::record('guest_script.run', $admin, $server, [
            'event' => $event->value,
            'hostname' => $server->hostname,
            'draft' => $draft,
            'trace' => $trace,
        ], $request->ip());

        $this->toast('info', 'Running the script on :hostname…', ['hostname' => $server->hostname]);

        return back();
    }

    /**
     * One run with its script, inputs and output, for the log dialog.
     */
    public function log(GuestScriptLog $guestScriptLog): JsonResponse
    {
        $guestScriptLog->load(['server:id,uuid,hostname', 'task:id,status,type,input']);

        return response()->json([
            ...self::summary($guestScriptLog),
            'script' => $guestScriptLog->script,
            'inputs' => $guestScriptLog->inputs,
            'stdout' => $guestScriptLog->stdout,
            'stderr' => $guestScriptLog->stderr,
            'output_truncated' => $guestScriptLog->output_truncated,
            // Custom scripts never get the password (docs/adr/0043).
            'output_kept' => $guestScriptLog->script_name !== null || $guestScriptLog->event !== GuestScriptEvent::PasswordReset,
        ]);
    }

    private function slot(OsTemplate $osTemplate, GuestScriptEvent $event): ?GuestScript
    {
        return $osTemplate->guestScripts()->where('event', $event->value)->first();
    }

    /**
     * The latest runs of each event on the template's servers.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function logs(OsTemplate $osTemplate): array
    {
        $logs = [];

        foreach (GuestScriptEvent::cases() as $event) {
            $logs[$event->value] = array_values(GuestScriptLog::query()
                ->where('os_template_id', $osTemplate->id)
                ->where('event', $event->value)
                // Custom scripts run at events too; they are listed with their script.
                ->whereNull('script_name')
                ->with(['server:id,uuid,hostname', 'task:id,status,type,input'])
                ->latest('id')
                ->limit(self::LOGS_PER_EVENT)
                ->get()
                ->map(self::summary(...))
                ->all());
        }

        return $logs;
    }

    /**
     * The latest runs of each custom script of the template, by its uuid.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function customLogs(OsTemplate $osTemplate): array
    {
        $logs = [];

        foreach ($osTemplate->customScripts()->get() as $script) {
            $logs[$script->uuid] = array_values(GuestScriptLog::query()
                ->where('custom_script_id', $script->id)
                ->with(['server:id,uuid,hostname', 'task:id,status,type,input'])
                ->latest('id')
                ->limit(self::LOGS_PER_EVENT)
                ->get()
                ->map(self::summary(...))
                ->all());
        }

        return $logs;
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(GuestScriptLog $log): array
    {
        // A run whose task ended without finishing it (the worker died) is over.
        $abandoned = $log->status === 'running' && $log->task !== null
            && ! in_array($log->task->status, [TaskStatus::Queued, TaskStatus::Running], true);
        $byHand = $log->task?->type === TaskType::GuestScript;

        return [
            'uuid' => $log->uuid,
            'server' => $log->server->hostname,
            'server_uuid' => $log->server->uuid,
            'script_name' => $log->script_name,
            'source' => $log->source,
            'trace' => $log->trace,
            // operation, trial (on the template page) or manual (from the server page, docs/adr/0043)
            'kind' => match (true) {
                $byHand && ($log->task->input['manual'] ?? false) === true => 'manual',
                $byHand => 'trial',
                default => 'operation',
            },
            'status' => $abandoned ? 'failed' : $log->status,
            'exit_code' => $log->exit_code,
            'message' => $abandoned ? __('The run ended without a result.') : $log->message,
            'started_at' => $log->created_at?->toIso8601String(),
            'finished_at' => $log->finished_at?->toIso8601String(),
        ];
    }
}
