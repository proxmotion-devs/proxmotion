<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OsPlatform;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomScriptRequest;
use App\Http\Requests\Admin\GuestScriptRequest;
use App\Models\AuditLog;
use App\Models\CustomScript;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administrators' own scripts on an OS template (docs/adr/0043): created
 * with a first draft, run by hand from the server page (manual) and/or after
 * the fixed script of some events. Drafts work as for the fixed scripts
 * (docs/adr/0023): only published content runs outside trials. Every change
 * is written to the audit log.
 */
class CustomScriptController extends Controller
{
    public function store(CustomScriptRequest $request, OsTemplate $osTemplate, #[CurrentUser] User $admin): RedirectResponse
    {
        $script = new CustomScript;
        $script->forceFill([
            'os_template_id' => $osTemplate->id,
            'name' => $request->string('name')->value(),
            'manual' => $request->boolean('manual'),
            'events' => $request->events(),
            'position' => (int) $osTemplate->customScripts()->max('position') + 1,
            'draft_content' => $request->script(),
        ])->save();

        AuditLog::record('custom_script.created', $admin, $osTemplate, [
            'script' => $script->name,
            'manual' => $script->manual,
            'events' => $script->events,
            'after' => $script->draft_content,
        ], $request->ip());

        $this->toast('success', 'Script added as a draft. Try it on a server, then publish it.');

        return back();
    }

    /**
     * The name and triggers; they apply at once, also to the published script.
     */
    public function update(CustomScriptRequest $request, OsTemplate $osTemplate, CustomScript $customScript, #[CurrentUser] User $admin): RedirectResponse
    {
        $before = ['name' => $customScript->name, 'manual' => $customScript->manual, 'events' => $customScript->events];
        $customScript->forceFill([
            'name' => $request->string('name')->value(),
            'manual' => $request->boolean('manual'),
            'events' => $request->events(),
        ])->save();
        $after = ['name' => $customScript->name, 'manual' => $customScript->manual, 'events' => $customScript->events];

        if ($before !== $after) {
            AuditLog::record('custom_script.updated', $admin, $osTemplate, ['before' => $before, 'after' => $after], $request->ip());
        }

        $this->toast('success', 'Script settings saved.');

        return back();
    }

    public function saveDraft(GuestScriptRequest $request, OsTemplate $osTemplate, CustomScript $customScript, #[CurrentUser] User $admin): RedirectResponse
    {
        $before = $customScript->draft_content;
        $customScript->forceFill(['draft_content' => $request->script()])->save();

        AuditLog::record('custom_script.draft_saved', $admin, $osTemplate, [
            'script' => $customScript->name,
            'before' => $before,
            'after' => $customScript->draft_content,
        ], $request->ip());

        $this->toast('success', 'Draft saved. Try it on a server, then publish it.');

        return back();
    }

    public function publish(Request $request, OsTemplate $osTemplate, CustomScript $customScript, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($customScript->draft_content === null) {
            $this->toast('error', 'There is no draft to publish.');

            return back();
        }

        $before = $customScript->content;
        $customScript->forceFill(['content' => $customScript->draft_content, 'draft_content' => null])->save();

        AuditLog::record('custom_script.published', $admin, $osTemplate, [
            'script' => $customScript->name,
            'before' => $before,
            'after' => $customScript->content,
        ], $request->ip());

        $this->toast('success', 'Script published.');

        return back();
    }

    public function discard(Request $request, OsTemplate $osTemplate, CustomScript $customScript, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($customScript->draft_content !== null) {
            $before = $customScript->draft_content;
            $customScript->forceFill(['draft_content' => null])->save();

            AuditLog::record('custom_script.draft_discarded', $admin, $osTemplate, [
                'script' => $customScript->name,
                'before' => $before,
            ], $request->ip());
        }

        $this->toast('success', 'Draft discarded.');

        return back();
    }

    /**
     * Its runs stay in the log under its name.
     */
    public function destroy(Request $request, OsTemplate $osTemplate, CustomScript $customScript, #[CurrentUser] User $admin): RedirectResponse
    {
        AuditLog::record('custom_script.deleted', $admin, $osTemplate, [
            'script' => $customScript->name,
            'manual' => $customScript->manual,
            'events' => $customScript->events,
            'before' => $customScript->content,
            'draft' => $customScript->draft_content,
        ], $request->ip());

        $customScript->delete();

        $this->toast('success', 'Script deleted.');

        return back();
    }

    /**
     * One place up or down: the order in which scripts bound to the same event run.
     */
    public function move(Request $request, OsTemplate $osTemplate, CustomScript $customScript, #[CurrentUser] User $admin): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $scripts = $osTemplate->customScripts()->get()->values();
        $index = $scripts->search(fn (CustomScript $script) => $script->is($customScript));
        $target = is_int($index) ? ($direction === 'up' ? $index - 1 : $index + 1) : -1;

        if (! is_int($index) || ! $scripts->has($target)) {
            return back();
        }

        DB::transaction(function () use ($scripts, $index, $target) {
            // Positions may repeat after deletes: renumber all, with the two swapped.
            $order = $scripts->all();
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

            foreach ($order as $position => $script) {
                $script->forceFill(['position' => $position + 1])->save();
            }
        });

        AuditLog::record('custom_script.moved', $admin, $osTemplate, [
            'script' => $customScript->name,
            'direction' => $direction,
        ], $request->ip());

        return back();
    }

    /**
     * A trial on one active server of the template: the draft or the
     * published script, whatever its triggers.
     */
    public function run(Request $request, OsTemplate $osTemplate, CustomScript $customScript, #[CurrentUser] User $admin, TaskRunner $tasks): RedirectResponse
    {
        $validated = $request->validate([
            'server' => ['required', 'string', Rule::exists('servers', 'uuid')->where('os_template_id', $osTemplate->id)->where('status', ServerStatus::Active->value)],
            'draft' => ['sometimes', 'boolean'],
            'trace' => ['sometimes', 'boolean'],
        ]);

        $draft = $request->boolean('draft');

        if (($draft ? $customScript->draft_content : $customScript->content) === null) {
            $this->toast('error', $draft ? 'There is no draft to run.' : 'The script is not published.');

            return back();
        }

        $server = Server::query()->where('uuid', $validated['server'])->firstOrFail();
        $trace = $request->boolean('trace') && $osTemplate->platform === OsPlatform::Linux;

        return $this->start($request, $tasks, $admin, $server, $customScript, ['draft' => $draft, 'trace' => $trace]);
    }

    /**
     * A manual script's published content, from the server page.
     */
    public function runOnServer(Request $request, Server $server, CustomScript $customScript, #[CurrentUser] User $admin, TaskRunner $tasks): RedirectResponse
    {
        abort_unless($customScript->os_template_id === $server->os_template_id && $customScript->manual && $customScript->content !== null, 404);

        if ($server->status !== ServerStatus::Active) {
            $this->toast('error', 'This action is not possible while the server is :status.', ['status' => __(ucfirst($server->status->value))]);

            return back();
        }

        return $this->start($request, $tasks, $admin, $server, $customScript, ['manual' => true]);
    }

    /**
     * @param  array<string, bool>  $input
     */
    private function start(Request $request, TaskRunner $tasks, User $admin, Server $server, CustomScript $script, array $input): RedirectResponse
    {
        try {
            $tasks->start($server, TaskType::GuestScript, $admin, input: ['script' => $script->uuid, ...$input]);
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        AuditLog::record('custom_script.run', $admin, $server, [
            'script' => $script->name,
            'hostname' => $server->hostname,
            ...$input,
        ], $request->ip());

        $this->toast('info', 'Running :script on :hostname…', ['script' => $script->name, 'hostname' => $server->hostname]);

        return back();
    }
}
