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
     * Choose whether new servers may put their disks on this storage.
     */
    public function update(Request $request, Storage $storage): RedirectResponse
    {
        $validated = $request->validate(['use_for_servers' => ['required', 'boolean']]);

        if ($validated['use_for_servers'] && ! $storage->supportsVmDisks()) {
            throw ValidationException::withMessages([
                'use_for_servers' => __('This storage does not accept VM disks (content type "images").'),
            ]);
        }

        $storage->update($validated);

        return back();
    }
}
