<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use Inertia\Inertia;

trait ReportsAddedAddresses
{
    /**
     * Toast like "3 addresses added. 1 already existed. Skipped: 203.0.113.11 (Proxmox node)."
     *
     * @param  array{added: int, existing: int, protected: array<string, string>}  $result
     */
    protected function reportAddedAddresses(array $result): void
    {
        $parts = [__(':count addresses added.', ['count' => (string) $result['added']])];

        if ($result['existing'] > 0) {
            $parts[] = __(':count already existed.', ['count' => (string) $result['existing']]);
        }

        if ($result['protected'] !== []) {
            $skipped = [];

            foreach ($result['protected'] as $address => $reason) {
                $skipped[] = "{$address} (".__($reason).')';
            }

            $parts[] = __('Skipped: :list.', ['list' => implode(', ', $skipped)]);
        }

        Inertia::flash('toast', [
            'type' => $result['added'] > 0 ? 'success' : 'info',
            'message' => implode(' ', $parts),
        ]);
    }
}
