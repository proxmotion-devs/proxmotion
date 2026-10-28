<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Servers\ServerSshKeys;
use App\Http\Controllers\Controller;
use App\Http\Requests\SshKeysRequest;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * A server's SSH keys on its admin page (docs/adr/0049): the same whole-list
 * changes customers make through the billing API.
 */
class ServerSshKeyController extends Controller
{
    public function __construct(private readonly ServerSshKeys $sshKeys) {}

    public function update(SshKeysRequest $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            $this->sshKeys->change($server, $request->sshKeys(), $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        }

        $this->toast('info', 'Changing the SSH keys…');

        return back();
    }
}
