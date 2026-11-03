<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

use Closure;
use Throwable;
use WISECP\Modules\Servers\Proxmotion;

/**
 * Receives Proxmotion's webhooks (POST /api/v1/proxmotion/webhook): checks
 * the signature against the signing secret of this module's WISECP servers,
 * drops repeated events and updates the service the event is about.
 *
 * Events arrive at least once and in no particular order, so a status is only
 * changed after the server's current state was fetched from the API, and only
 * where WISECP would otherwise show something that is no longer true.
 * Terminations are never applied: whether WISECP stops billing is the
 * administrators' decision, they are told instead.
 */
final class WebhookReceiver
{
    public const SECRET_PREFIX = 'whsec_';

    /** @var Closure(): int */
    private Closure $clock;

    /** @var list<string> masked in the module log */
    private array $secrets = [];

    /**
     * @param Closure(array<string, mixed>): ApiClient $clientFor the API client of a WISECP server
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly WebhookStore $store,
        private readonly Closure $clientFor,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, string> $headers names in lower case
     * @return array{status: int, body: string}
     */
    public function handle(string $method, array $headers, string $body): array
    {
        if (strtoupper($method) !== 'POST') {
            return $this->answer(405, 'Only POST is accepted.');
        }

        $signature = new WebhookSignature($headers['proxmotion-signature'] ?? '');

        if (! $signature->isWellFormed()) {
            return $this->reject(400, 'Missing or malformed Proxmotion-Signature header.');
        }

        if (! $signature->isFresh(($this->clock)())) {
            return $this->reject(400, 'The signature timestamp is too old or too far ahead.');
        }

        $servers = $this->signedBy($signature, $body);

        if ($servers === []) {
            return $this->reject(401, 'Invalid signature.');
        }

        $event = json_decode($body, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['event'] ?? null)) {
            return $this->reject(400, 'The body is not a Proxmotion event.');
        }

        if (! $this->store->claimEvent($event['id'])) {
            return $this->logged($event, $body, 200, 'Already received.');
        }

        try {
            $result = $this->process($event, $servers);
        } catch (ApiException $e) {
            // Proxmotion is asked again later; the event is retried.
            $this->store->releaseEvent($event['id']);

            return $this->logged($event, $body, 503, 'Proxmotion could not be asked: ' . $e->describe());
        } catch (Throwable $e) {
            $this->store->releaseEvent($event['id']);

            return $this->logged($event, $body, 500, 'Error: ' . $e->getMessage());
        }

        return $this->logged($event, $body, 200, $result);
    }

    /**
     * The WISECP servers whose signing secret signed the body.
     *
     * @return list<array<string, mixed>>
     */
    private function signedBy(WebhookSignature $signature, string $body): array
    {
        $matching = [];

        foreach ($this->store->servers() as $server) {
            $secret = Proxmotion::field($server, 'webhook_secret');

            if (! str_starts_with($secret, self::SECRET_PREFIX)) {
                continue;
            }

            $this->secrets[] = $secret;

            if ($signature->matches($body, $secret)) {
                $matching[] = $server;
            }
        }

        return $matching;
    }

    /**
     * @param array<string, mixed> $event
     * @param list<array<string, mixed>> $servers
     */
    private function process(array $event, array $servers): string
    {
        if ($event['event'] === 'ping') {
            return 'Pong.';
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $externalId = (string) ($data['server']['external_id'] ?? '');

        if (! ctype_digit($externalId)) {
            return 'Ignored: the server has no WISECP service id.';
        }

        $service = $this->store->service((int) $externalId, array_map(static fn (array $server): int => (int) $server['id'], $servers));

        if ($service === null) {
            return 'Ignored: service #' . $externalId . ' is not on a Proxmotion server with this signing secret.';
        }

        $hostname = (string) ($data['server']['hostname'] ?? '');
        $error = is_string($data['task']['error'] ?? null) ? $data['task']['error'] : '';
        $current = fn (): ?array => ($this->clientFor)($servers[0])->findServer((string) $service['id']);

        return match ($event['event']) {
            'server.provisioned' => $this->provisioned($service, $current()),
            'server.suspended' => $this->changeStatus($service, $current(), 'suspended', 'active', 'suspended'),
            'server.unsuspended' => $this->changeStatus($service, $current(), 'active', 'suspended', 'active'),
            'server.terminated' => $this->terminated($service, $hostname),
            'server.failed' => $this->failed($service, $hostname, $error),
            // server.reinstalled, server.moved, server.restored, task.succeeded, task.failed: the module log is enough.
            default => 'Logged.',
        };
    }

    /**
     * A first installation finished: the service shows the server's hostname
     * and addresses, and a service still waiting or in process becomes active.
     *
     * @param array{id: int, owner_id: int, status: string, options: array<string, mixed>} $service
     * @param array<string, mixed>|null $current
     */
    private function provisioned(array $service, ?array $current): string
    {
        if ($current === null || $current['status'] !== 'active') {
            return 'Nothing changed: the server is no longer active.';
        }

        $addresses = [];

        foreach (is_array($current['addresses'] ?? null) ? $current['addresses'] : [] as $address) {
            if (is_array($address) && isset($address['address'])) {
                $addresses[] = (string) $address['address'];
            }
        }

        $values = array_filter([
            'hostname' => (string) ($current['hostname'] ?? ''),
            'ip' => $addresses[0] ?? '',
            'assigned_ips' => implode("\n", array_slice($addresses, 1)),
        ], static fn (string $value, string $key): bool => $value !== '' || $key === 'assigned_ips', ARRAY_FILTER_USE_BOTH);

        $changed = array_keys(array_filter($values, static fn (string $value, string $key): bool => (string) ($service['options'][$key] ?? '') !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $this->store->updateOptions($service['id'], $values);
        }

        if (in_array($service['status'], ['waiting', 'inprocess'], true)) {
            $this->store->changeStatus($service['id'], 'active', '');
            $changed[] = 'status';
        }

        return $changed === [] ? 'Nothing to change.' : 'Updated: ' . implode(', ', $changed) . '.';
    }

    /**
     * Suspended or unsuspended in the panel: WISECP follows when the server is
     * still in that state and the service is in the opposite one.
     *
     * @param array{id: int, owner_id: int, status: string, options: array<string, mixed>} $service
     * @param array<string, mixed>|null $current
     */
    private function changeStatus(array $service, ?array $current, string $serverStatus, string $from, string $to): string
    {
        if ($current === null || $current['status'] !== $serverStatus) {
            return 'Nothing changed: the server is no longer ' . $serverStatus . '.';
        }

        if ($service['status'] !== $from) {
            return 'Nothing to change.';
        }

        $this->store->changeStatus($service['id'], $to, $to === 'suspended' ? 'Suspended in Proxmotion' : '');
        $this->store->history($service['id'], 'Proxmotion: server ' . $serverStatus . ' in the panel, service status set to ' . $to . '.');

        return 'Status set to ' . $to . '.';
    }

    /**
     * @param array{id: int, owner_id: int, status: string, options: array<string, mixed>} $service
     */
    private function terminated(array $service, string $hostname): string
    {
        if ($service['status'] !== 'active' && $service['status'] !== 'suspended') {
            return 'Nothing to change.';
        }

        $this->store->notifyAdmins($service['id'], 'The server ' . $hostname . ' of service #' . $service['id'] . ' was terminated in Proxmotion. The WISECP service is still ' . $service['status'] . ': cancel it in WISECP if it should no longer be billed.');
        $this->store->history($service['id'], 'Proxmotion: server terminated in the panel, service left ' . $service['status'] . '.');

        return 'Administrators notified.';
    }

    /**
     * @param array{id: int, owner_id: int, status: string, options: array<string, mixed>} $service
     */
    private function failed(array $service, string $hostname, string $error): string
    {
        $this->store->notifyAdmins($service['id'], 'A task of the server ' . $hostname . ' (service #' . $service['id'] . ') failed in Proxmotion and the server is in error. '
            . ($error !== '' ? 'Error: ' . $error . ' ' : '')
            . 'Check the task in the Proxmotion admin panel and retry it there.');
        $this->store->history($service['id'], 'Proxmotion: server task failed' . ($error !== '' ? ': ' . $error : '') . '.');

        return 'Administrators notified.';
    }

    /**
     * A request that was not accepted. Its body is not logged: anyone can send one.
     *
     * @return array{status: int, body: string}
     */
    private function reject(int $status, string $message): array
    {
        $this->store->log('webhook rejected', '', $status . ' ' . $message);

        return $this->answer($status, $message);
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: int, body: string}
     */
    private function logged(array $event, string $body, int $status, string $message): array
    {
        $this->store->log('webhook ' . $event['event'], str_replace($this->secrets, '***', $body), $status . ' ' . $message);

        return $this->answer($status, $message);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function answer(int $status, string $message): array
    {
        return ['status' => $status, 'body' => $message];
    }
}
