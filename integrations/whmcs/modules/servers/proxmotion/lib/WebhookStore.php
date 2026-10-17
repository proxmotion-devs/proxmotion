<?php

namespace Proxmotion\Whmcs;

/**
 * What the webhook receiver reads and writes in the WHMCS database
 * (WhmcsWebhookStore; faked in the tests).
 */
interface WebhookStore
{
    /**
     * The WHMCS servers of this module as module parameters: serverid,
     * serverhostname, serverip, serversecure, serverport, serverpassword
     * (decrypted) and serveraccesshash.
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
     * A service on one of these WHMCS servers: id, userid, domain, status
     * (Pending, Active, Suspended, Terminated, …).
     *
     * @param list<int> $serverIds
     * @return array{id: int, userid: int, domain: string, status: string}|null
     */
    public function service(int $serviceId, array $serverIds): ?array;
}
