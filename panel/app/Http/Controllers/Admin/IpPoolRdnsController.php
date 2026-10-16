<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Networking\ReverseDns\PtrName;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IpPool;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * For "record only" pools (docs/adr/0003): export the PTRs to apply them by
 * hand, then mark them as applied.
 */
class IpPoolRdnsController extends Controller
{
    /**
     * Every PTR of the pool as CSV: address, PTR record name, hostname, pending.
     */
    public function export(IpPool $ipPool): StreamedResponse
    {
        $addresses = $ipPool->addresses()->whereNotNull('ptr')->orderBy('id')->get(['address', 'ptr', 'ptr_synced_at']);

        return response()->streamDownload(function () use ($addresses) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['address', 'ptr_name', 'hostname', 'pending'], escape: '');

            foreach ($addresses as $address) {
                fputcsv($out, [
                    $address->address,
                    PtrName::for($address->address).'.',
                    "{$address->ptr}.",
                    $address->ptr_synced_at === null ? 'yes' : 'no',
                ], escape: '');
            }

            fclose($out);
        }, "rdns-{$ipPool->name}.csv", ['Content-Type' => 'text/csv']);
    }

    public function markApplied(Request $request, IpPool $ipPool, #[CurrentUser] User $admin): RedirectResponse
    {
        $count = $ipPool->addresses()->whereNotNull('ptr')->whereNull('ptr_synced_at')->update(['ptr_synced_at' => now()]);

        AuditLog::record('rdns.marked_applied', $admin, $ipPool, ['count' => $count], $request->ip());
        $this->toast('success', ':count PTR records marked as applied.', ['count' => (string) $count]);

        return back();
    }
}
