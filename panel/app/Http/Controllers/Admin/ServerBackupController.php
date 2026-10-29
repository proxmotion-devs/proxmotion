<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Backups\ServerBackups;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A server's backups on its admin page (docs/adr/0051 §4): the same
 * actions customers have through the billing API. A restore is confirmed
 * by typing the hostname, like a reinstall.
 */
class ServerBackupController extends Controller
{
    public function __construct(private readonly ServerBackups $backups) {}

    public function store(Request $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        $label = $request->validate(['label' => ['nullable', 'string', 'max:'.ServerBackups::LABEL_LENGTH, 'regex:/^[^\p{C}]*$/u']])['label'] ?? null;

        return $this->attempt(fn () => $this->backups->take($server, $label, $admin, $request->ip()), 'Taking the backup…');
    }

    public function restore(Request $request, Server $server, Backup $backup, #[CurrentUser] User $admin): RedirectResponse
    {
        $this->ensureBelongs($server, $backup);

        if (strcasecmp($request->string('confirm_hostname')->trim()->value(), $server->hostname) !== 0) {
            throw ValidationException::withMessages(['confirm_hostname' => __('Type the hostname of the server to confirm.')]);
        }

        return $this->attempt(fn () => $this->backups->restore($server, $backup, $admin, $request->ip()), 'Restoring the backup…');
    }

    public function destroy(Request $request, Server $server, Backup $backup, #[CurrentUser] User $admin): RedirectResponse
    {
        $this->ensureBelongs($server, $backup);

        return $this->attempt(fn () => $this->backups->delete($server, $backup, $admin, $request->ip()), 'Deleting the backup…');
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

    private function ensureBelongs(Server $server, Backup $backup): void
    {
        abort_if($backup->server_id !== $server->id, 404);
    }
}
