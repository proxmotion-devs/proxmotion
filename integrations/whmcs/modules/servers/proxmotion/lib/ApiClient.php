<?php

namespace Proxmotion\Whmcs;

/**
 * A thin client of the Proxmotion billing API v1 (docs/api/openapi.yaml in the
 * Proxmotion repository). Every call is logged with WHMCS's logModuleCall(),
 * with the token and passwords masked.
 */
class ApiClient
{
    /** GET /servers answers 50 servers per page: this is 50,000 servers. */
    const MAX_PAGES = 1000;

    /** @var string e.g. https://panel.example.com/api/v1 */
    private $baseUrl;

    /** @var string */
    private $token;

    /** @var Transport */
    private $transport;

    /** @var list<string> values masked in the module log */
    private $secrets;

    public function __construct(string $baseUrl, string $token, Transport $transport)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->transport = $transport;
        $this->secrets = [$token];
    }

    /**
     * Mask this value in the module log, e.g. a password sent or received.
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
    public function osTemplates(?string $serverId = null): array
    {
        $query = $serverId === null ? [] : ['server' => $serverId];

        return $this->listOf($this->request('GET', '/os-templates' . ($query === [] ? '' : '?' . http_build_query($query))));
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
     * Every server of the billing system, page by page (at most MAX_PAGES pages).
     *
     * @return list<array<string, mixed>>
     */
    public function allServers(): array
    {
        $servers = [];
        $page = 1;

        do {
            $response = $this->request('GET', '/servers?' . http_build_query(['page' => $page]));
            $servers = array_merge($servers, $this->listOf($response));
            $last = isset($response['meta']['last_page']) ? (int) $response['meta']['last_page'] : $page;
            $page++;
        } while ($page <= $last && $page <= self::MAX_PAGES);

        return $servers;
    }

    /**
     * The live (not terminated) server of a WHMCS service, newest first.
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
     * @param list<string>|null $sshKeys the new system's keys; null keeps the server's keys (docs/adr/0049)
     * @param string|null $app an app installed on the new system (docs/adr/0016 §5), with its defaults
     * @return array<string, mixed> the queued task
     */
    public function reinstall(string $id, string $osTemplate, ?array $sshKeys, ?string $app = null): array
    {
        $body = ['os_template' => $osTemplate];

        if ($sshKeys !== null) {
            $body['ssh_keys'] = $sshKeys;
        }

        if ($app !== null) {
            $body['app'] = $app;
        }

        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/reinstall', $body));
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
     * Apps that can be installed on the server now (docs/adr/0015).
     *
     * @return list<array<string, mixed>>
     */
    public function apps(string $serverId): array
    {
        return $this->listOf($this->request('GET', '/apps?' . http_build_query(['server' => $serverId])));
    }

    /**
     * Apps that can come with a new system (docs/adr/0016 §5): for an order
     * with this OS template, or for a reinstall of this server.
     *
     * @return list<array<string, mixed>>
     */
    public function orderApps(?string $osTemplate, ?string $serverId = null): array
    {
        $query = ['mode' => 'order'];

        if ($osTemplate !== null) {
            $query['os_template'] = $osTemplate;
        }

        if ($serverId !== null) {
            $query['server'] = $serverId;
        }

        return $this->listOf($this->request('GET', '/apps?' . http_build_query($query)));
    }

    /**
     * Grant the right to install a paid app (docs/adr/0019); granting again changes nothing.
     *
     * @return array<string, mixed> the entitlement
     */
    public function grantEntitlement(string $id, string $app, ?string $reference): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/app-entitlements', ['app' => $app, 'reference' => $reference]));
    }

    /**
     * Revoke the right to install a paid app; false when the server had none.
     */
    public function revokeEntitlement(string $id, string $app): bool
    {
        try {
            $this->request('DELETE', '/servers/' . rawurlencode($id) . '/app-entitlements/' . rawurlencode($app));
        } catch (ApiException $e) {
            if ($e->status() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * The server's app installations, newest first, with outputs (apps:secrets scope).
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
     * @param array<string, string|int|bool> $inputs
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
     * Cancel a running installation (docs/adr/0030): it fails at once and its
     * installer is stopped inside the server.
     *
     * @return array<string, mixed> the installation
     */
    public function cancelApp(string $id, string $installation, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation) . '/cancel', null, $idempotencyKey));
    }

    /**
     * Cancel a running uninstall, upgrade or action (docs/adr/0030).
     *
     * @return array<string, mixed> the run
     */
    public function cancelAppRun(string $id, string $installation, string $run, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation) . '/runs/' . rawurlencode($run) . '/cancel', null, $idempotencyKey));
    }

    /**
     * Run the app's uninstall script (docs/adr/0016).
     *
     * @return array<string, mixed> the queued run
     */
    public function uninstallApp(string $id, string $installation, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('DELETE', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation), null, $idempotencyKey));
    }


    /**
     * Upgrade an installed app to the version its `upgrade` names (docs/adr/0016),
     * with values for the inputs that version adds.
     *
     * @param array<string, string> $inputs
     * @return array<string, mixed> the queued run
     */
    public function upgradeApp(string $id, string $installation, array $inputs, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/apps/' . rawurlencode($installation) . '/upgrade', ['inputs' => (object) $inputs], $idempotencyKey));
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
    /**
     * The server's firewall: default_policy, rules, app_rules (docs/adr/0035).
     *
     * @return array<string, mixed>
     */
    public function firewall(string $id): array
    {
        return $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id) . '/firewall'));
    }

    /**
     * Set the default policy ("accept" or "drop") and replace the rules; returns the task.
     *
     * @param list<array<string, string|null>> $rules
     * @return array<string, mixed>
     */
    public function updateFirewall(string $id, string $policy, array $rules, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('PUT', '/servers/' . rawurlencode($id) . '/firewall', ['default_policy' => $policy, 'rules' => $rules], $idempotencyKey));
    }

    /**
     * Apply a rule set ("replace" or "append"); returns the task.
     *
     * @return array<string, mixed>
     */
    public function applyFirewallRuleset(string $id, string $ruleset, string $mode, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/firewall/ruleset', ['ruleset' => $ruleset, 'mode' => $mode], $idempotencyKey));
    }

    /**
     * Active firewall rule sets.
     *
     * @return list<array<string, mixed>>
     */
    public function firewallRulesets(): array
    {
        return $this->listOf($this->request('GET', '/firewall-rulesets'));
    }

    /**
     * The SSH keys Proxmotion manages on the server: ssh_keys, max_keys (docs/adr/0049).
     *
     * @return array<string, mixed>
     */
    public function sshKeys(string $id): array
    {
        return $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id) . '/ssh-keys'));
    }

    /**
     * The server's snapshots: snapshots, slots, unavailable_reason (docs/adr/0051).
     *
     * @return array<string, mixed>
     */
    public function snapshots(string $id): array
    {
        return $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id) . '/snapshots'));
    }

    /**
     * Take a snapshot; returns the task.
     *
     * @return array<string, mixed>
     */
    public function createSnapshot(string $id, string $label, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/snapshots', ['label' => $label === '' ? null : $label], $idempotencyKey));
    }

    /**
     * Roll the server back to a snapshot; returns the task.
     *
     * @return array<string, mixed>
     */
    public function rollbackSnapshot(string $id, string $snapshot, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/snapshots/' . rawurlencode($snapshot) . '/rollback', null, $idempotencyKey));
    }

    /**
     * Delete a snapshot; returns the task.
     *
     * @return array<string, mixed>
     */
    public function deleteSnapshot(string $id, string $snapshot, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('DELETE', '/servers/' . rawurlencode($id) . '/snapshots/' . rawurlencode($snapshot), null, $idempotencyKey));
    }

    /**
     * The server's backups: backups, slots, unavailable_reason (docs/adr/0051 §4).
     *
     * @return array<string, mixed>
     */
    public function backups(string $id): array
    {
        return $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id) . '/backups'));
    }

    /**
     * Take a backup; returns the task.
     *
     * @return array<string, mixed>
     */
    public function createBackup(string $id, string $label, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/backups', ['label' => $label === '' ? null : $label], $idempotencyKey));
    }

    /**
     * Restore a backup onto the server; returns the task.
     *
     * @return array<string, mixed>
     */
    public function restoreBackup(string $id, string $backup, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('POST', '/servers/' . rawurlencode($id) . '/backups/' . rawurlencode($backup) . '/restore', null, $idempotencyKey));
    }

    /**
     * Delete a backup; returns the task.
     *
     * @return array<string, mixed>
     */
    public function deleteBackup(string $id, string $backup, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('DELETE', '/servers/' . rawurlencode($id) . '/backups/' . rawurlencode($backup), null, $idempotencyKey));
    }

    /**
     * The server's usage over a range: day, week, month or year (docs/adr/0050);
     * Proxmotion answers 404 before it has usage history.
     *
     * @return array<string, mixed>
     */
    public function usage(string $id, string $range): array
    {
        return $this->dataOf($this->request('GET', '/servers/' . rawurlencode($id) . '/usage?range=' . rawurlencode($range)));
    }

    /**
     * Replace the server's SSH keys with these public keys; returns the task.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function updateSshKeys(string $id, array $keys, string $idempotencyKey): array
    {
        return $this->dataOf($this->request('PUT', '/servers/' . rawurlencode($id) . '/ssh-keys', ['ssh_keys' => $keys], $idempotencyKey));
    }

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
        if (!function_exists('logModuleCall')) {
            return;
        }

        logModuleCall('proxmotion', $action, self::maskPasswords((string) $request), self::maskPasswords($response['body']), 'HTTP ' . $response['status'], $this->secrets);
    }

    /**
     * Root passwords in JSON bodies (sent or received) never reach the module log.
     */
    private static function maskPasswords(string $json): string
    {
        $decoded = json_decode($json, true);

        // App inputs (may hold passwords) and outputs (VPN profiles, generated passwords) too.
        if (is_array($decoded)) {
            return (string) json_encode(self::maskSecrets($decoded), JSON_UNESCAPED_SLASHES);
        }

        return (string) preg_replace('/("root_password"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/', '$1"***"', $json);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function maskSecrets(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['root_password', 'inputs', 'app_inputs', 'value', 'content_base64'], true) && $value !== null) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::maskSecrets($value);
            }
        }

        return $data;
    }
}
