<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\SshKey;

/**
 * What the reinstall dialog offers: the OS templates the server can use and
 * its owner's saved SSH keys (fingerprints, never the keys themselves).
 */
final class ReinstallOptions
{
    /**
     * @return array{osTemplates: list<array{value: string, label: string}>, sshKeys: list<array{value: string, label: string, fingerprint: string}>}
     */
    public static function for(Server $server): array
    {
        return [
            'osTemplates' => array_values(app(ServerReinstaller::class)->eligibleTemplates($server)
                ->map(fn (OsTemplate $template) => ['value' => $template->uuid, 'label' => $template->name])
                ->all()),
            'sshKeys' => array_values($server->user->sshKeys()->orderBy('name')->get()
                ->map(fn (SshKey $key) => ['value' => $key->uuid, 'label' => $key->name, 'fingerprint' => $key->fingerprint])
                ->all()),
        ];
    }
}
