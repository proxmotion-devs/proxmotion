<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an administrator: Administrators → New and proxmotion:install
 * (docs/adr/0052 §3). Every panel user is an administrator (docs/adr/0007).
 */
class CreateAdministrator
{
    /**
     * @param  bool  $checkPassword  false for a password the panel generated:
     *                               random, so the strength rules (and their
     *                               breach lookup in production) are pointless
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $checkPassword = true): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', ...($checkPassword ? [Password::defaults()] : [])],
        ];
    }

    /**
     * @param  array{name: string, email: string, password: string}  $validated
     * @param  array<string, string>  $properties  added to the audit entry
     */
    public function create(array $validated, ?User $by, ?string $ipAddress = null, array $properties = []): User
    {
        $user = new User;
        $user->forceFill([
            ...$validated,
            // Created by an administrator or the installer: the address
            // doesn't need to be confirmed again.
            'email_verified_at' => now(),
        ])->save();

        AuditLog::record('user.created', $by, $user, ['email' => $user->email, ...$properties], $ipAddress);

        return $user;
    }
}
