<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Servers\GuestScripts;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\TaskFailer;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * End a stuck task (queued, or running past its time limit) as failed, so
     * the server takes operations again. Proxmox is not touched.
     */
    public function fail(Request $request, Task $task, #[CurrentUser] User $admin, TaskFailer $failer): RedirectResponse
    {
        if (! $failer->canAbandon($task)) {
            $this->toast('error', 'This task can no longer be marked as failed.');

            return back();
        }

        $status = $task->status->value;
        $failer->fail($task, 'Marked as failed by an administrator.');

        AuditLog::record('task.marked_failed', $admin, $task->server, [
            'task' => $task->uuid,
            'type' => $task->type->value,
            'status' => $status,
        ], $request->ip());

        $this->toast('success', 'The task is marked as failed.');

        return back();
    }

    /**
     * Cancel an administrator's run of a script or a backup: the task fails
     * at once and the server takes operations again; the job running a
     * script stops it inside the server within a few seconds (GuestScripts),
     * a backup is stopped in Proxmox (docs/adr/0051 §4).
     */
    public function cancel(Request $request, Task $task, #[CurrentUser] User $admin, TaskFailer $failer): RedirectResponse
    {
        $status = $task->status->value;

        if (! $failer->canCancel($task) || ! $failer->cancel($task, GuestScripts::CANCELLED)) {
            $this->toast('error', 'This task can no longer be cancelled.');

            return back();
        }

        AuditLog::record('task.cancelled', $admin, $task->server, [
            'task' => $task->uuid,
            'type' => $task->type->value,
            'status' => $status,
        ], $request->ip());

        $this->toast('success', $task->type === TaskType::CreateBackup
            ? 'The backup was cancelled and is being stopped in Proxmox.'
            : 'The script was cancelled and is being stopped inside the server.');

        return back();
    }
}
