<?php

declare(strict_types=1);

namespace App\Domain\Servers;

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

/**
 * The public SSH keys the panel manages on a running server (docs/adr/0049):
 * the keys of its create or reinstall, then those added and deleted later.
 * A change_ssh_keys task writes them to root's (Administrator's)
 * authorized_keys through the ssh_keys_changed guest script; the server's
 * list changes only once that succeeded.
 */
final readonly class ServerSshKeys
{
    public const MAX_KEYS = 20;

    public function __construct(private TaskRunner $tasks) {}

    /**
     * @return list<PublicKey>
     */
    public static function of(Server $server): array
    {
        return PublicKey::parseMany((string) $server->ssh_keys);
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

        $lines = is_array($task->input['ssh_keys'] ?? null) ? $task->input['ssh_keys'] : [];

        return PublicKey::parseMany(implode("\n", array_filter($lines, is_string(...))));
    }

    /**
     * The server's keys the task's change takes away: recorded on the task
     * when it starts (SshKeysPipeline), since the server's list changes once
     * the script succeeded and custom scripts run after that.
     *
     * @return list<PublicKey>
     */
    public static function removed(Server $server, ?Task $task): array
    {
        $recorded = $task?->type === TaskType::ChangeSshKeys ? ($task->input['removed_ssh_keys'] ?? null) : null;

        if (is_array($recorded)) {
            return PublicKey::parseMany(implode("\n", array_filter($recorded, is_string(...))));
        }

        return self::without(self::of($server), self::wanted($server, $task));
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
     * @param  list<PublicKey>  $keys  validated, at most MAX_KEYS
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
