<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Servers\ReinstallLimits;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * How often the server's billing system may reinstall it (docs/adr/0045):
 * its package's limit or its own, and resetting what it has used.
 */
class ServerReinstallLimitController extends Controller
{
    public function update(Request $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate([
            'custom' => ['required', 'boolean'],
            ...ReinstallLimits::rules(),
        ]);

        $custom = (bool) $data['custom'];
        $limit = $custom && isset($data['reinstall_limit']) ? (int) $data['reinstall_limit'] : null;

        $server->forceFill([
            'reinstall_limit_custom' => $custom,
            'reinstall_limit' => $limit,
            'reinstall_period_days' => $limit === null ? null : (int) $data['reinstall_period_days'],
        ]);

        if ($server->isDirty()) {
            $server->save();
            AuditLog::record('server.reinstall_limit_changed', $admin, $server, [
                'hostname' => $server->hostname,
                'custom' => $custom,
                'limit' => $server->reinstall_limit,
                'period_days' => $server->reinstall_period_days,
            ], $request->ip());
        }

        $this->toast('success', 'Reinstall limit saved.');

        return back();
    }

    /**
     * Only reinstalls after now count towards the limit.
     */
    public function reset(Request $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        $server->forceFill(['reinstalls_reset_at' => now()])->save();
        AuditLog::record('server.reinstalls_reset', $admin, $server, ['hostname' => $server->hostname], $request->ip());

        $this->toast('success', 'Reinstall count reset.');

        return back();
    }
}
