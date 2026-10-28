<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\OsPlatform;
use App\Models\Server;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Support\Ssh\PublicKey;
use InvalidArgumentException;

/**
 * The SSH keys that really are inside a running server (docs/adr/0049 §11):
 * the authorized_keys files of root (Windows: Administrator) read through
 * the guest agent's file-read, next to the keys the panel manages. It only
 * reads; an administrator may then let the panel manage a key found here.
 */
final readonly class GuestSshKeys
{
    /** Key types a line may name, as PublicKey accepts them. */
    private const string LINE = '/(?:^|\s)((?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com))\s+([A-Za-z0-9+\/=]+)(?:\s+(.*))?$/';

    public function __construct(
        private ProxmoxClientFactory $clients,
        private GuestScripts $scripts,
    ) {}

    /**
     * The files sshd reads for root, or on Windows for administrators (the
     * shared file) and the Administrator's own file, where Cloudbase-Init
     * puts the keys of a new server.
     *
     * @return list<string>
     */
    public static function paths(OsPlatform $platform): array
    {
        return $platform === OsPlatform::Windows
            ? ['C:\\ProgramData\\ssh\\administrators_authorized_keys', 'C:\\Users\\Administrator\\.ssh\\authorized_keys']
            : ['/root/.ssh/authorized_keys'];
    }

    /**
     * @return array{
     *     keys: list<array{type: string, fingerprint: string, comment: string, public_key: string, file: string, options: bool, managed: bool}>,
     *     missing: list<string>,
     *     unsupported: int,
     * }
     *  keys: the keys found, each once; missing: fingerprints of managed keys
     *  found in no file; unsupported: key lines the panel cannot manage
     *  (e.g. DSA, short RSA keys)
     *
     * @throws GuestSshKeysUnavailable when the guest agent does not answer
     */
    public function read(Server $server): array
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);

        if (! $agent->ping($server->vmid)) {
            throw new GuestSshKeysUnavailable($server);
        }

        $managed = array_map(fn (PublicKey $key) => $key->fingerprint(), ServerSshKeys::of($server));
        $keys = [];
        $unsupported = 0;

        foreach (self::paths($this->scripts->platform($server)) as $path) {
            try {
                $content = $agent->readFile($server->vmid, $path)->content;
            } catch (ProxmoxException) {
                // No such file: nothing in it.
                continue;
            }

            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                [$key, $options] = self::parse($line);

                if ($key === null) {
                    $unsupported++;

                    continue;
                }

                $keys[$key->fingerprint()] ??= [
                    'type' => $key->type,
                    'fingerprint' => $key->fingerprint(),
                    'comment' => $key->comment,
                    'public_key' => $key->toString(),
                    'file' => $path,
                    'options' => $options,
                    'managed' => in_array($key->fingerprint(), $managed, true),
                ];
            }
        }

        return [
            'keys' => array_values($keys),
            'missing' => array_values(array_diff($managed, array_keys($keys))),
            'unsupported' => $unsupported,
        ];
    }

    /**
     * The key of an authorized_keys line, which may start with options
     * (command="…",no-pty …), and whether it does.
     *
     * @return array{PublicKey|null, bool}
     */
    private static function parse(string $line): array
    {
        if (preg_match(self::LINE, $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [null, false];
        }

        try {
            $key = PublicKey::parse($match[1][0].' '.$match[2][0].' '.($match[3][0] ?? ''));
        } catch (InvalidArgumentException) {
            return [null, false];
        }

        return [$key, trim(substr($line, 0, $match[0][1])) !== ''];
    }
}
