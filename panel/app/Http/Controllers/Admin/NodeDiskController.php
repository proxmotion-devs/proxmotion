<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NodeDisk;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NodeDiskController extends Controller
{
    /**
     * Forget a disk Proxmox no longer reports, e.g. after it was replaced (docs/adr/0047).
     */
    public function destroy(Request $request, NodeDisk $disk, #[CurrentUser] User $admin): RedirectResponse
    {
        abort_if($disk->is_present, 409, __('Proxmox still reports this disk.'));

        AuditLog::record('node_disk.forgotten', $admin, $disk->node, [
            'devpath' => $disk->devpath,
            'model' => $disk->model,
            'serial' => $disk->serial,
        ], $request->ip());

        $disk->delete();

        $this->toast('success', 'Disk forgotten.');

        return back();
    }
}
