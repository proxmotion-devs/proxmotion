<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SshKey;
use App\Models\User;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's saved public SSH keys. Users only ever see and change
 * their own keys: lookups go through $user->sshKeys().
 */
class SshKeyController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/SshKeys', [
            'keys' => $user->sshKeys()->latest('id')->get()->map(fn (SshKey $key) => [
                'uuid' => $key->uuid,
                'name' => $key->name,
                'type' => strtok($key->public_key, ' '),
                'fingerprint' => $key->fingerprint,
                'created_at' => $key->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'public_key' => ['required', 'string', 'max:16384', new SshPublicKeys(single: true)],
        ]);

        $key = PublicKey::parse($validated['public_key']);

        if ($user->sshKeys()->where('fingerprint', $key->fingerprint())->exists()) {
            throw ValidationException::withMessages(['public_key' => __('This key is already saved.')]);
        }

        $sshKey = new SshKey;
        $sshKey->forceFill([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'public_key' => $key->toString(),
            'fingerprint' => $key->fingerprint(),
        ])->save();

        $this->toast('success', 'SSH key added.');

        return to_route('ssh-keys.index');
    }

    public function destroy(#[CurrentUser] User $user, string $sshKey): RedirectResponse
    {
        $user->sshKeys()->where('uuid', $sshKey)->firstOrFail()->delete();

        $this->toast('success', 'SSH key deleted.');

        return to_route('ssh-keys.index');
    }
}
