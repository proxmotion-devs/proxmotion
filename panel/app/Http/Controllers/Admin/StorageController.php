<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StorageController extends Controller
{
    /**
     * Choose whether new servers may put their disks on this storage, and
     * whether backups of servers may go to it (docs/adr/0051 §4).
     */
    public function update(Request $request, Storage $storage): RedirectResponse
    {
        $validated = $request->validate([
            'use_for_servers' => ['sometimes', 'required', 'boolean'],
            'use_for_backups' => ['sometimes', 'required', 'boolean'],
        ]);

        if (($validated['use_for_servers'] ?? false) && ! $storage->supportsVmDisks()) {
            throw ValidationException::withMessages([
                'use_for_servers' => __('This storage does not accept VM disks (content type "images").'),
            ]);
        }

        if (($validated['use_for_backups'] ?? false) && ! $storage->supportsBackups()) {
            throw ValidationException::withMessages([
                'use_for_backups' => __('This storage does not accept backups (content type "backup").'),
            ]);
        }

        $storage->update($validated);

        return back();
    }
}
