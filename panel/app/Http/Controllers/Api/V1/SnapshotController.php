<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Backups\ServerSnapshots;
use App\Domain\Backups\SnapshotSlotsFull;
use App\Domain\Backups\SnapshotsUnavailable;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Snapshot;
use App\Models\Task;
use App\Provisioning\Exceptions\ProvisioningException;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A server's snapshots (docs/adr/0051 §7): list them, take one, roll back
 * to one or delete one. Changes answer 202 with the task; the list changes
 * once it succeeded.
 */
class SnapshotController extends ApiController
{
    public function __construct(private readonly ServerSnapshots $snapshots) {}

    public function index(Server $server): JsonResponse
    {
        return response()->json(['data' => [
            'snapshots' => ServerSnapshots::describe($server),
            'slots' => $server->snapshot_slots,
            // Why none can be taken at all, or null.
            'unavailable_reason' => ServerSnapshots::unavailable($server)?->translatedMessage(),
        ]]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $label = $request->validate(['label' => ['nullable', 'string', 'max:'.ServerSnapshots::LABEL_LENGTH, 'regex:/^[^\p{C}]*$/u']])['label'] ?? null;

        return $this->attempt($server, fn () => $this->snapshots->take($server, $label, $this->token($request), $request->ip()));
    }

    public function rollback(Request $request, Server $server, string $snapshot): JsonResponse
    {
        $snapshot = $this->snapshotOf($server, $snapshot);

        return $this->attempt($server, fn () => $this->snapshots->rollback($server, $snapshot, $this->token($request), $request->ip()));
    }

    public function destroy(Request $request, Server $server, string $snapshot): JsonResponse
    {
        $snapshot = $this->snapshotOf($server, $snapshot);

        return $this->attempt($server, fn () => $this->snapshots->delete($server, $snapshot, $this->token($request), $request->ip()));
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
        } catch (SnapshotsUnavailable $e) {
            return response()->json(['message' => $e->translatedMessage(), 'error' => 'snapshots_not_supported'], 422);
        } catch (SnapshotSlotsFull $e) {
            return response()->json(['message' => $e->translatedMessage(), 'error' => 'snapshot_slots_full'], 409);
        } catch (ProvisioningException $e) {
            return $this->conflict($e->translatedMessage());
        }
    }

    /**
     * Looked up here, after the server was scoped to the token's billing
     * system: a route binding would answer before that and tell a foreign
     * server from an unknown one.
     */
    private function snapshotOf(Server $server, string $uuid): Snapshot
    {
        return $server->snapshots()->where('uuid', $uuid)->first()
            ?? throw (new ModelNotFoundException)->setModel(Snapshot::class, [$uuid]);
    }
}
