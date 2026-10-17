<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

/**
 * What the webhook receiver reads and writes in WISECP (WisecpWebhookStore;
 * faked in the tests).
 */
interface WebhookStore
{
    /**
     * The active WISECP servers of this module as WISECP passes them to the
     * module: id, name, ip, secure, port and fields (decrypted).
     *
     * @return list<array<string, mixed>>
     */
    public function servers(): array;

    /**
     * Remember an event id. False when it was seen before.
     */
    public function claimEvent(string $eventId): bool;

    /**
     * Forget an event id again, so a repeat of the event is processed.
     */
    public function releaseEvent(string $eventId): void;

    /**
     * A service of this module on one of these WISECP servers: id, owner_id,
     * status (waiting, inprocess, active, suspended, cancelled, …) and options.
     *
     * @param list<int> $serverIds
     * @return array{id: int, owner_id: int, status: string, options: array<string, mixed>}|null
     */
    public function service(int $serviceId, array $serverIds): ?array;

    /**
     * Change a service's status without running the module again.
     */
    public function changeStatus(int $serviceId, string $status, string $reason): void;

    /**
     * Merge these values into the service's options (hostname, ip, assigned_ips).
     *
     * @param array<string, mixed> $values
     */
    public function updateOptions(int $serviceId, array $values): void;

    /**
     * A line in the service's history, shown to administrators.
     */
    public function history(int $serviceId, string $message): void;

    /**
     * Tell administrators that something needs their attention on a service.
     */
    public function notifyAdmins(int $serviceId, string $message): void;

    /**
     * A module log entry (Tools → Module logs).
     */
    public function log(string $action, string $request, string $response): void;
}
