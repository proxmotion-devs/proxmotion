<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Backups\ServerSnapshots;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Snapshot;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A server's snapshots on its admin page (docs/adr/0051): the same actions
 * customers have through the billing API. A rollback is confirmed by typing
 * the hostname, like a reinstall.
 */
class ServerSnapshotController extends Controller
{
    public function __construct(private readonly ServerSnapshots $snapshots) {}

    public function store(Request $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        $label = $request->validate(['label' => ['nullable', 'string', 'max:'.ServerSnapshots::LABEL_LENGTH, 'regex:/^[^\p{C}]*$/u']])['label'] ?? null;

        return $this->attempt(fn () => $this->snapshots->take($server, $label, $admin, $request->ip()), 'Taking the snapshot…');
    }

    public function rollback(Request $request, Server $server, Snapshot $snapshot, #[CurrentUser] User $admin): RedirectResponse
    {
        $this->ensureBelongs($server, $snapshot);

        if (strcasecmp($request->string('confirm_hostname')->trim()->value(), $server->hostname) !== 0) {
            throw ValidationException::withMessages(['confirm_hostname' => __('Type the hostname of the server to confirm.')]);
        }

        return $this->attempt(fn () => $this->snapshots->rollback($server, $snapshot, $admin, $request->ip()), 'Rolling back to the snapshot…');
    }

    public function destroy(Request $request, Server $server, Snapshot $snapshot, #[CurrentUser] User $admin): RedirectResponse
    {
        $this->ensureBelongs($server, $snapshot);

        return $this->attempt(fn () => $this->snapshots->delete($server, $snapshot, $admin, $request->ip()), 'Deleting the snapshot…');
    }

    /**
     * @param  Closure(): mixed  $start
     */
    private function attempt(Closure $start, string $started): RedirectResponse
    {
        try {
            $start();
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        }

        $this->toast('info', $started);

        return back();
    }

    private function ensureBelongs(Server $server, Snapshot $snapshot): void
    {
        abort_if($snapshot->server_id !== $server->id, 404);
    }
}
