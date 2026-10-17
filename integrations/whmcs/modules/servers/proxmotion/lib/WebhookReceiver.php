<?php

namespace Proxmotion\Whmcs;

use Exception;

/**
 * Receives Proxmotion's webhooks (webhook.php): checks the signature against
 * the signing secret in the access hash of this module's WHMCS servers, drops
 * repeated events and updates the service the event is about.
 *
 * Events arrive at least once and in no particular order, so a status is only
 * changed after the server's current state was fetched from the API, and only
 * where WHMCS would otherwise show something that is no longer true.
 * Terminations are never applied: whether WHMCS stops billing is the
 * administrators' decision, they are told instead.
 */
class WebhookReceiver
{
    const SECRET_PREFIX = 'whsec_';

    /** @var WebhookStore */
    private $store;

    /** @var callable(array<string, mixed>): ApiClient */
    private $clientFor;

    /** @var callable(): int */
    private $clock;

    /** @var list<string> masked in the module log */
    private $secrets = [];

    /**
     * @param callable(array<string, mixed>): ApiClient $clientFor the API client of a WHMCS server's parameters
     * @param callable(): int|null $clock
     */
    public function __construct(WebhookStore $store, callable $clientFor, ?callable $clock = null)
    {
        $this->store = $store;
        $this->clientFor = $clientFor;
        $this->clock = $clock !== null ? $clock : static function () {
            return time();
        };
    }

    /**
     * Whether a WHMCS server's access hash holds a webhook signing secret.
     */
    public static function isSecret(string $value): bool
    {
        return strncmp(trim($value), self::SECRET_PREFIX, strlen(self::SECRET_PREFIX)) === 0;
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

        $signature = new WebhookSignature(isset($headers['proxmotion-signature']) ? $headers['proxmotion-signature'] : '');

        if (!$signature->isWellFormed()) {
            return $this->reject($body, 400, 'Missing or malformed Proxmotion-Signature header.');
        }

        if (!$signature->isFresh(call_user_func($this->clock))) {
            return $this->reject($body, 400, 'The signature timestamp is too old or too far ahead.');
        }

        $servers = $this->signedBy($signature, $body);

        if ($servers === []) {
            return $this->reject($body, 401, 'Invalid signature.');
        }

        $event = json_decode($body, true);

        if (!is_array($event) || !isset($event['id'], $event['event']) || !is_string($event['id']) || !is_string($event['event'])) {
            return $this->reject($body, 400, 'The body is not a Proxmotion event.');
        }

        if (!$this->store->claimEvent($event['id'])) {
            return $this->logged($event, $body, 200, 'Already received.');
        }

        try {
            $result = $this->process($event, $servers);
        } catch (ApiException $e) {
            // Proxmotion is asked again later; the event is retried.
            $this->store->releaseEvent($event['id']);

            return $this->logged($event, $body, 503, 'Proxmotion could not be asked: ' . $e->describe());
        } catch (Exception $e) {
            $this->store->releaseEvent($event['id']);

            return $this->logged($event, $body, 500, 'Error: ' . $e->getMessage());
        }

        return $this->logged($event, $body, 200, $result);
    }

    /**
     * The WHMCS servers whose signing secret signed the body.
     *
     * @return list<array<string, mixed>>
     */
    private function signedBy(WebhookSignature $signature, string $body): array
    {
        $matching = [];

        foreach ($this->store->servers() as $server) {
            $secret = trim(isset($server['serveraccesshash']) ? (string) $server['serveraccesshash'] : '');

            if (!self::isSecret($secret)) {
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

        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];
        $externalId = isset($data['server']['external_id']) ? (string) $data['server']['external_id'] : '';

        if (!ctype_digit($externalId)) {
            return 'Ignored: the server has no WHMCS service id.';
        }

        $serverIds = [];

        foreach ($servers as $server) {
            $serverIds[] = (int) $server['serverid'];
        }

        $service = $this->store->service((int) $externalId, $serverIds);

        if ($service === null) {
            return 'Ignored: service #' . $externalId . ' is not on a Proxmotion server with this signing secret.';
        }

        $hostname = isset($data['server']['hostname']) ? (string) $data['server']['hostname'] : '';
        $error = isset($data['task']['error']) && is_string($data['task']['error']) ? $data['task']['error'] : '';

        switch ($event['event']) {
            case 'server.provisioned':
                return $this->provisioned($service, $this->current($servers[0], $service));
            case 'server.suspended':
                return $this->changeStatus($service, $this->current($servers[0], $service), 'suspended', 'Active', 'Suspended');
            case 'server.unsuspended':
                return $this->changeStatus($service, $this->current($servers[0], $service), 'active', 'Suspended', 'Active');
            case 'server.terminated':
                return $this->terminated($service, $hostname);
            case 'server.failed':
                return $this->failed($service, $hostname, $error);
            default:
                // server.reinstalled, task.succeeded, task.failed: the module log is enough.
                return 'Logged.';
        }
    }

    /**
     * The service's live server in Proxmotion now (null: none, or terminated).
     *
     * @param array<string, mixed> $server
     * @param array{id: int, userid: int, domain: string, status: string} $service
     * @return array<string, mixed>|null
     */
    private function current(array $server, array $service): ?array
    {
        return call_user_func($this->clientFor, $server)->findServer((string) $service['id']);
    }

    /**
     * A first installation finished: a service still Pending becomes Active,
     * and the domain follows the server's hostname.
     *
     * @param array{id: int, userid: int, domain: string, status: string} $service
     * @param array<string, mixed>|null $current
     */
    private function provisioned(array $service, ?array $current): string
    {
        if ($current === null || $current['status'] !== 'active') {
            return 'Nothing changed: the server is no longer active.';
        }

        $values = [];

        if ($service['status'] === 'Pending') {
            $values['status'] = 'Active';
        }

        if (isset($current['hostname']) && (string) $current['hostname'] !== '' && (string) $current['hostname'] !== $service['domain']) {
            $values['domain'] = (string) $current['hostname'];
        }

        if ($values === []) {
            return 'Nothing to change.';
        }

        $this->updateService($service, $values);

        return 'Updated: ' . implode(', ', array_keys($values)) . '.';
    }

    /**
     * Suspended or unsuspended in the panel: WHMCS follows when the server is
     * still in that state and the service is in the opposite one.
     *
     * @param array{id: int, userid: int, domain: string, status: string} $service
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

        $this->updateService($service, ['status' => $to]);
        $this->activity($service, 'Proxmotion: server ' . $serverStatus . ' in the panel, service status set to ' . $to);

        return 'Status set to ' . $to . '.';
    }

    /**
     * @param array{id: int, userid: int, domain: string, status: string} $service
     */
    private function terminated(array $service, string $hostname): string
    {
        if ($service['status'] !== 'Active' && $service['status'] !== 'Suspended') {
            return 'Nothing to change.';
        }

        $message = 'The server ' . $hostname . ' of service #' . $service['id'] . ' was terminated in Proxmotion. The WHMCS service is still ' . $service['status'] . ': terminate or cancel it in WHMCS if it should no longer be billed.';
        $this->notifyAdmins('Proxmotion: server of service #' . $service['id'] . ' terminated', $message);
        $this->activity($service, 'Proxmotion: server terminated in the panel, service left ' . $service['status']);

        return 'Administrators notified.';
    }

    /**
     * @param array{id: int, userid: int, domain: string, status: string} $service
     */
    private function failed(array $service, string $hostname, string $error): string
    {
        $message = 'A task of the server ' . $hostname . ' (service #' . $service['id'] . ') failed in Proxmotion and the server is in error. '
            . ($error !== '' ? 'Error: ' . $error . ' ' : '')
            . 'Check the task in the Proxmotion admin panel and retry it there.';
        $this->notifyAdmins('Proxmotion: server of service #' . $service['id'] . ' failed', $message);
        $this->activity($service, 'Proxmotion: server task failed' . ($error !== '' ? ': ' . $error : ''));

        return 'Administrators notified.';
    }

    /**
     * @param array{id: int, userid: int, domain: string, status: string} $service
     * @param array<string, string> $values
     */
    private function updateService(array $service, array $values): void
    {
        $this->localApi('UpdateClientProduct', ['serviceid' => $service['id']] + $values);
    }

    private function notifyAdmins(string $subject, string $message): void
    {
        $this->localApi('SendAdminEmail', ['customsubject' => $subject, 'custommessage' => htmlspecialchars($message), 'type' => 'account']);
    }

    /**
     * @param array{id: int, userid: int, domain: string, status: string} $service
     */
    private function activity(array $service, string $description): void
    {
        $this->localApi('LogActivity', ['description' => $description . ' (service #' . $service['id'] . ')', 'userid' => $service['userid']]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function localApi(string $command, array $values): void
    {
        $result = localAPI($command, $values);

        if (!isset($result['result']) || $result['result'] !== 'success') {
            throw new Exception($command . ' failed: ' . (isset($result['message']) ? (string) $result['message'] : 'no answer'));
        }
    }

    /**
     * A request that was not accepted, logged without trusting its content.
     *
     * @return array{status: int, body: string}
     */
    private function reject(string $body, int $status, string $message): array
    {
        logModuleCall('proxmotion', 'webhook rejected', $body, $status . ' ' . $message, '', $this->secrets);

        return $this->answer($status, $message);
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: int, body: string}
     */
    private function logged(array $event, string $body, int $status, string $message): array
    {
        logModuleCall('proxmotion', 'webhook ' . $event['event'], $body, $status . ' ' . $message, '', $this->secrets);

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
