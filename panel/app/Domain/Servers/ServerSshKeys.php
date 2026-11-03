<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Domain\Settings\PanelSettings;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use App\Support\Ssh\PublicKey;
use InvalidArgumentException;

/**
 * The public SSH keys the panel manages on a running server (docs/adr/0049):
 * the keys of its create or reinstall, then those added and deleted later.
 * A change_ssh_keys task writes them to root's (Administrator's)
 * authorized_keys through the ssh_keys_changed guest script; the server's
 * list changes only once that succeeded.
 */
final readonly class ServerSshKeys
{
    /** The highest key limit Panel settings accept. */
    public const int CEILING = 100;

    /**
     * The keys of a server together, as normalized lines. A change writes
     * them and the keys it removes (at most as many bytes again) in one
     * input file, and the guest agent writes at most
     * GuestAgentResource::MAX_WRITE_BYTES at once.
     */
    public const int MAX_BYTES = 20000;

    public function __construct(private TaskRunner $tasks) {}

    /**
     * How many keys a server may have (Panel settings, docs/adr/0049 §12).
     */
    public static function max(): int
    {
        $max = PanelSettings::get('ssh_keys_max');

        return is_numeric($max) ? max(1, min(self::CEILING, (int) $max)) : 20;
    }

    /**
     * Why these keys cannot be a server's keys, translated, or null.
     *
     * @param  list<PublicKey>  $keys
     */
    public static function problem(array $keys): ?string
    {
        if (count($keys) > self::max()) {
            return __('A server can have at most :count SSH keys.', ['count' => self::max()]);
        }

        $bytes = array_sum(array_map(fn (PublicKey $key) => strlen($key->toString()) + 1, $keys));

        if ($bytes > self::MAX_BYTES) {
            return __('The SSH keys are too long together: at most :size KB.', ['size' => intdiv(self::MAX_BYTES, 1000)]);
        }

        return null;
    }

    /**
     * The server's keys. A stored line today's rules refuse (e.g. after a
     * stricter minimum) is left out here instead of breaking every page and
     * API answer that lists the keys; removedLines() still takes it away.
     *
     * @return list<PublicKey>
     */
    public static function of(Server $server): array
    {
        return self::parse(self::lines($server));
    }

    /**
     * The stored authorized_keys lines as they are.
     *
     * @return list<string>
     */
    private static function lines(Server $server): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/', (string) $server->ssh_keys) ?: []), fn (string $line) => $line !== ''));
    }

    /**
     * @param  list<mixed>  $lines
     * @return list<PublicKey>
     */
    private static function parse(array $lines): array
    {
        $keys = [];

        foreach ($lines as $line) {
            try {
                $key = is_string($line) ? PublicKey::parse($line) : null;
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($key !== null) {
                $keys[$key->fingerprint()] ??= $key;
            }
        }

        return array_values($keys);
    }

    /**
     * The base64 key of an authorized_keys line ("type key comment"), which
     * the guest scripts compare.
     */
    private static function blob(string $line): string
    {
        return preg_split('/\s+/', trim($line))[1] ?? '';
    }

    /**
     * @return list<array{type: string, fingerprint: string, comment: string, public_key: string}>
     */
    public static function describe(Server $server): array
    {
        return array_map(fn (PublicKey $key) => [
            'type' => $key->type,
            'fingerprint' => $key->fingerprint(),
            'comment' => $key->comment,
            'public_key' => $key->toString(),
        ], self::of($server));
    }

    /**
     * The keys a change_ssh_keys task asks for; any other task (a trial of
     * the script) keeps the server's keys.
     *
     * @return list<PublicKey>
     */
    public static function wanted(Server $server, ?Task $task): array
    {
        if ($task?->type !== TaskType::ChangeSshKeys) {
            return self::of($server);
        }

        return self::parse(array_values(is_array($task->input['ssh_keys'] ?? null) ? $task->input['ssh_keys'] : []));
    }

    /**
     * The stored lines the task's change takes away, also lines that no
     * longer parse: recorded on the task when it starts (SshKeysPipeline),
     * since the server's list changes once the script succeeded and custom
     * scripts run after that.
     *
     * @return list<string>
     */
    public static function removedLines(Server $server, ?Task $task): array
    {
        $recorded = $task?->type === TaskType::ChangeSshKeys ? ($task->input['removed_ssh_keys'] ?? null) : null;

        if (is_array($recorded)) {
            return array_values(array_filter($recorded, is_string(...)));
        }

        $kept = array_map(fn (PublicKey $key) => self::blob($key->toString()), self::wanted($server, $task));

        return array_values(array_filter(self::lines($server), fn (string $line) => ! in_array(self::blob($line), $kept, true)));
    }

    /**
     * The keys of $from that $to no longer has, by fingerprint.
     *
     * @param  list<PublicKey>  $from
     * @param  list<PublicKey>  $to
     * @return list<PublicKey>
     */
    public static function without(array $from, array $to): array
    {
        $kept = array_map(fn (PublicKey $key) => $key->fingerprint(), $to);

        return array_values(array_filter($from, fn (PublicKey $key) => ! in_array($key->fingerprint(), $kept, true)));
    }

    /**
     * Queue a change to exactly these keys. Sending the server's own list
     * again runs the script again, e.g. after a failed change.
     *
     * @param  list<PublicKey>  $keys  validated, within problem()
     *
     * @throws ProvisioningException
     */
    public function change(Server $server, array $keys, User|ApiToken $actor, ?string $ipAddress): Task
    {
        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }

        $current = self::of($server);
        $task = $this->tasks->start($server, TaskType::ChangeSshKeys, $actor, input: [
            'ssh_keys' => array_map(fn (PublicKey $key) => $key->toString(), $keys),
        ]);

        AuditLog::record('server.ssh_keys_changed', $actor, $server, [
            'added' => array_map(fn (PublicKey $key) => $key->fingerprint(), self::without($keys, $current)),
            'removed' => array_map(fn (PublicKey $key) => $key->fingerprint(), self::without($current, $keys)),
        ], $ipAddress);

        return $task;
    }
}
