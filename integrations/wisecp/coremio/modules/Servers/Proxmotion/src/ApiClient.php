<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

use Closure;

/**
 * A thin client of the Proxmotion billing API v1 (docs/api/openapi.yaml in the
 * Proxmotion repository). Every call goes to the logger with the token and
 * passwords masked (the module writes it to WISECP's module log).
 */
final class ApiClient
{
    private readonly string $baseUrl;

    /** @var list<string> values masked in the log */
    private array $secrets;

    /**
     * @param string $baseUrl e.g. https://panel.example.com/api/v1
     * @param (Closure(string, string, string): void)|null $logger action, request, response
     */
    public function __construct(
        string $baseUrl,
        private readonly string $token,
        private readonly Transport $transport,
        private readonly ?Closure $logger = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->secrets = [$token];
    }

    /**
     * Mask this value in the log, e.g. a password sent or received.
     */
    public function addSecret(string $value): void
    {
        if ($value !== '') {
            $this->secrets[] = $value;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packages(): array
    {
        return $this->listOf($this->request('GET', '/packages'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function locations(): array
    {
        return $this->listOf($this->request('GET', '/locations'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function osTemplates(): array
    {
        return $this->listOf($this->request('GET', '/os-templates'));
    }

    /**
     * The first page of the billing system's servers.
     *
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        return $this->listOf($this->request('GET', '/servers'));
    }

    /**
     * The live (not terminated) server of a WISECP service, newest first.
     *
     * @return array<string, mixed>|null
     */
    public function findServer(string $externalId): ?array
    {
        $servers = $this->listOf($this->request('GET', '/servers?' . http_build_query(['external_id' => $externalId])));

        foreach (array_reverse($servers) as $server) {
            if (($server['status'] ?? null) !== 'terminated') {
                return $server;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function server(string $id): array
    {
        return $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id)));
    }

    /**
     * @return array<string, mixed>
     */
    public function task(string $id): array
    {
        return $this->dataOf($this->request('GET', '/tasks/' . rawurlencode($id)));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the queued task
     */
    public function createServer(array $payload, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers', $payload, $idempotencyKey));
    }

    /**
     * @return array<string, mixed> the queued task
     */
    public function suspend(string $id, ?string $reason, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/suspend', ['reason' => $reason], $idempotencyKey));
    }

    /**
     * @return array<string, mixed> the queued task
     */
    public function unsuspend(string $id, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/unsuspend', [], $idempotencyKey));
    }

    /**
     * @return array<string, mixed> the queued task
     */
    public function terminate(string $id, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('DELETE', '/servers/' . rawurlencode($id), null, $idempotencyKey));
    }

    /**
     * @param string $action start, shutdown, reboot or stop
     * @return array<string, mixed> the queued task
     */
    public function power(string $id, string $action): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/power', ['action' => $action]));
    }

    /**
     * Upgrade or downgrade (POST /servers/{id}/package).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the queued task
     */
    public function changeResources(string $id, array $payload, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/package', $payload, $idempotencyKey));
    }

    /**
     * @param list<string> $sshKeys
     * @return array<string, mixed> the queued task
     */
    public function reinstall(string $id, string $osTemplate, array $sshKeys): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/reinstall', [
            'os_template' => $osTemplate,
            'ssh_keys' => $sshKeys,
        ]));
    }

    /**
     * @param string|null $password the new password, or null for a random one
     * @return array<string, mixed> the queued task
     */
    public function resetPassword(string $id, ?string $password): array
    {
        if ($password !== null) {
            $this->addSecret($password);
        }

        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/password', $password === null ? [] : ['root_password' => $password]));
    }

    /**
     * A new root password the panel made, or null when it was already fetched.
     */
    public function fetchPassword(string $id): ?string
    {
        try {
            $data = $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id) . '/password'));
        } catch (ApiException $e) {
            if ($e->status() === 410) {
                return null;
            }

            throw $e;
        }

        $password = isset($data['root_password']) && is_string($data['root_password']) ? $data['root_password'] : null;

        if ($password !== null) {
            $this->addSecret($password);
        }

        return $password;
    }

    /**
     * Rename the server (a task); $reboot restarts a Windows server so the name applies now.
     *
     * @return array<string, mixed>
     */
    public function changeHostname(string $id, string $hostname, bool $reboot, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/hostname', ['hostname' => $hostname, 'reboot' => $reboot], $idempotencyKey));
    }

    /**
     * @return array<string, mixed>
     */
    public function setRdns(string $id, string $address, ?string $hostname): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/rdns', ['address' => $address, 'hostname' => $hostname]));
    }

    /**
     * Apps that can be installed on the server now (ADR 0015 in the Proxmotion repository).
     *
     * @return list<array<string, mixed>>
     */
    public function apps(string $serverId): array
    {
        return $this->listOf($this->request('GET', '/apps?' . http_build_query(['server' => $serverId])));
    }

    /**
     * The server's app installations, newest first; outputs only with the apps:secrets scope.
     *
     * @return list<array<string, mixed>>
     */
    public function installations(string $id): array
    {
        return $this->listOf($this->request('GET', '/servers/' . rawurlencode($id) . '/apps'));
    }

    /**
     * @return array<string, mixed>
     */
    public function installation(string $id, string $installation): array
    {
        return $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation)));
    }

    /**
     * @param array<string, string> $inputs
     * @return array<string, mixed> the queued installation
     */
    public function installApp(string $id, string $app, array $inputs, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/apps', ['app' => $app, 'inputs' => (object) $inputs], $idempotencyKey));
    }

    /**
     * @return array<string, mixed> the queued installation
     */
    public function retryApp(string $id, string $installation, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation) . '/retry', null, $idempotencyKey));
    }

    /**
     * Run the app's uninstall script (ADR 0016 in the Proxmotion repository).
     *
     * @return array<string, mixed> the queued run
     */
    public function uninstallApp(string $id, string $installation, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('DELETE', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation), null, $idempotencyKey));
    }

    /**
     * Run an action of an installed app, e.g. adding a VPN client.
     *
     * @param array<string, string> $inputs
     * @return array<string, mixed> the queued run
     */
    public function runAppAction(string $id, string $installation, string $action, array $inputs, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation) . '/actions/' . rawurlencode($action), ['inputs' => (object) $inputs], $idempotencyKey));
    }

    /**
     * The installation's latest uninstalls and actions, newest first, with outputs (apps:secrets scope).
     *
     * @return list<array<string, mixed>>
     */
    public function appRuns(string $id, string $installation): array
    {
        return $this->listOf($this->request('GET', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation) . '/runs'));
    }

    /**
     * @return array<string, mixed>
     */
    public function appRun(string $id, string $installation, string $run): array
    {
        return $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation) . '/runs/' . rawurlencode($run)));
    }

    /**
     * A signed console link for the customer's browser (valid for 60 seconds, once).
     */
    public function consoleLink(string $id): string
    {
        $data = $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/console'));

        return isset($data['url']) && is_string($data['url']) ? $data['url'] : '';
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed> the decoded JSON answer
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $json = $body === null ? null : (string) json_encode($body);
        $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $json);

        // A write with an Idempotency-Key can safely be sent again when the connection failed.
        if ($response['status'] === 0 && $idempotencyKey !== null) {
            $this->log($method . ' ' . $path, $json, $response);
            $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $json);
        }

        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? $decoded : [];

        $this->log($method . ' ' . $path, $json, $response);

        if ($response['status'] === 0) {
            throw new ApiException('Could not connect to Proxmotion: ' . ($response['body'] !== '' ? $response['body'] : 'no answer'));
        }

        if ($response['status'] >= 400) {
            $message = isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : 'Proxmotion answered with HTTP ' . $response['status'] . '.';
            $errors = isset($decoded['errors']) && is_array($decoded['errors']) ? $decoded['errors'] : [];

            throw new ApiException($message, $response['status'], $errors);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function listOf(array $response): array
    {
        return array_values(array_filter($this->dataOf($response), 'is_array'));
    }

    /**
     * @param array{status: int, body: string} $response
     */
    private function log(string $action, ?string $request, array $response): void
    {
        if ($this->logger === null) {
            return;
        }

        ($this->logger)($action, $this->mask((string) $request), 'HTTP ' . $response['status'] . "\n" . $this->mask($response['body']));
    }

    /**
     * The token, passwords sent or received, root passwords, app inputs (may
     * hold passwords) and app outputs (VPN profiles) never reach the log.
     */
    private function mask(string $text): string
    {
        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            $text = (string) json_encode(self::maskSecrets($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $text = (string) preg_replace('/("root_password"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/', '$1"***"', $text);

        return str_replace(array_values(array_filter($this->secrets, static fn (string $secret): bool => $secret !== '')), '***', $text);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function maskSecrets(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['root_password', 'inputs', 'value', 'content_base64'], true) && $value !== null) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::maskSecrets($value);
            }
        }

        return $data;
    }
}
