<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

use Proxmotion\Wisecp\WebhookStore;

/**
 * WISECP as the webhook receiver sees it; records every change.
 */
final class FakeWebhookStore implements WebhookStore
{
    /** @var list<array<string, mixed>> */
    public array $servers = [];

    /** @var array<int, array{id: int, owner_id: int, status: string, options: array<string, mixed>, server: int}> */
    public array $services = [];

    /** @var array<string, true> */
    public array $events = [];

    /** @var list<string> "changeStatus 42 suspended (reason)", "updateOptions 42 {…}", "history 42 …", "notifyAdmins 42 …" */
    public array $changes = [];

    /** @var list<array{action: string, request: string, response: string}> */
    public array $logs = [];

    public function servers(): array
    {
        return $this->servers;
    }

    public function claimEvent(string $eventId): bool
    {
        if (isset($this->events[$eventId])) {
            return false;
        }

        $this->events[$eventId] = true;

        return true;
    }

    public function releaseEvent(string $eventId): void
    {
        unset($this->events[$eventId]);
    }

    public function service(int $serviceId, array $serverIds): ?array
    {
        if (! isset($this->services[$serviceId]) || ! in_array($this->services[$serviceId]['server'], $serverIds, true)) {
            return null;
        }

        $service = $this->services[$serviceId];
        unset($service['server']);

        return $service;
    }

    public function changeStatus(int $serviceId, string $status, string $reason): void
    {
        $this->changes[] = 'changeStatus ' . $serviceId . ' ' . $status . ($reason !== '' ? ' (' . $reason . ')' : '');
    }

    public function updateOptions(int $serviceId, array $values): void
    {
        $this->changes[] = 'updateOptions ' . $serviceId . ' ' . json_encode($values);
    }

    public function history(int $serviceId, string $message): void
    {
        $this->changes[] = 'history ' . $serviceId . ' ' . $message;
    }

    public function notifyAdmins(int $serviceId, string $message): void
    {
        $this->changes[] = 'notifyAdmins ' . $serviceId . ' ' . $message;
    }

    public function log(string $action, string $request, string $response): void
    {
        $this->logs[] = ['action' => $action, 'request' => $request, 'response' => $response];
    }
}
