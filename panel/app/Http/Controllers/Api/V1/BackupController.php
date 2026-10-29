<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Backups\BackupNotRestorable;
use App\Domain\Backups\BackupSlotsFull;
use App\Domain\Backups\BackupsUnavailable;
use App\Domain\Backups\ServerBackups;
use App\Enums\ServerStatus;
use App\Models\Backup;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\Exceptions\ProvisioningException;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A server's backups (docs/adr/0051 §7): list them, take one, restore one
 * or delete one. Changes answer 202 with the task; the list changes once
 * it succeeded. Never the storage or volume of a backup.
 */
class BackupController extends ApiController
{
    public function __construct(private readonly ServerBackups $backups) {}

    public function index(Server $server): JsonResponse
    {
        return response()->json(['data' => [
            'backups' => ServerBackups::describe($server),
            'slots' => $server->backup_slots,
            // Why none can be taken at all, or null.
            'unavailable_reason' => ServerBackups::unavailable($server)?->translatedMessage(),
        ]]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $label = $request->validate(['label' => ['nullable', 'string', 'max:'.ServerBackups::LABEL_LENGTH, 'regex:/^[^\p{C}]*$/u']])['label'] ?? null;

        return $this->attempt($server, fn () => $this->backups->take($server, $label, $this->token($request), $request->ip()));
    }

    public function restore(Request $request, Server $server, string $backup): JsonResponse
    {
        $backup = $this->backupOf($server, $backup);

        return $this->attempt($server, fn () => $this->backups->restore($server, $backup, $this->token($request), $request->ip()));
    }

    public function destroy(Request $request, Server $server, string $backup): JsonResponse
    {
        $backup = $this->backupOf($server, $backup);

        return $this->attempt($server, fn () => $this->backups->delete($server, $backup, $this->token($request), $request->ip()));
    }

    /**
     * @param  Closure(): Task  $start
     */
    private function attempt(Server $server, Closure $start): JsonResponse
    {
        if (! in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true)) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($start());
        } catch (BackupsUnavailable $e) {
            return response()->json(['message' => $e->translatedMessage(), 'error' => $e->error], 422);
        } catch (BackupSlotsFull $e) {
            return response()->json(['message' => $e->translatedMessage(), 'error' => 'backup_slots_full'], 409);
        } catch (BackupNotRestorable $e) {
            return response()->json(['message' => $e->translatedMessage(), 'error' => 'backup_not_restorable'], 409);
        } catch (ProvisioningException $e) {
            return $this->conflict($e->translatedMessage());
        }
    }

    /**
     * Looked up here, after the server was scoped to the token's billing
     * system: a route binding would answer before that and tell a foreign
     * server from an unknown one.
     */
    private function backupOf(Server $server, string $uuid): Backup
    {
        return $server->backups()->where('uuid', $uuid)->first()
            ?? throw (new ModelNotFoundException)->setModel(Backup::class, [$uuid]);
    }
}
