<?php

namespace Proxmotion\Whmcs\Tests;

use Proxmotion\Whmcs\WebhookStore;

/**
 * The WHMCS database as the webhook receiver sees it.
 */
class FakeWebhookStore implements WebhookStore
{
    /** @var list<array<string, mixed>> */
    public $servers = [];

    /** @var array<int, array{id: int, userid: int, domain: string, status: string, server: int}> */
    public $services = [];

    /** @var array<string, true> */
    public $events = [];

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
        if (!isset($this->services[$serviceId]) || !in_array($this->services[$serviceId]['server'], $serverIds, true)) {
            return null;
        }

        $service = $this->services[$serviceId];
        unset($service['server']);

        return $service;
    }
}
