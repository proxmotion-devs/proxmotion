<?php

namespace Proxmotion\Whmcs;

/**
 * Everything the WHMCS module does for one service. proxmotion.php only
 * forwards WHMCS's calls here. Business logic stays in Proxmotion: this
 * class maps WHMCS services onto API calls and back.
 */
class Module
{
    /** Waits for a password reset to finish before showing the new password. */
    const RESET_WAIT_SECONDS = 20;

    /** @var array<string, mixed> */
    private $params;

    /** @var ApiClient */
    private $client;

    /** @var Lang */
    private $lang;

    /** @var callable(int): void */
    private $sleep;

    /** @var array<string, mixed>|null|false false: not looked up yet */
    private $server = false;

    /**
     * @param array<string, mixed> $params
     * @param callable(int): void|null $sleep
     */
    public function __construct(array $params, ApiClient $client, ?callable $sleep = null)
    {
        $this->params = $params;
        $this->client = $client;
        $this->lang = Lang::forParams($params);
        $this->sleep = $sleep !== null ? $sleep : static function ($seconds) {
            sleep($seconds);
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params, ?Transport $transport = null): self
    {
        return new self($params, new ApiClient(self::baseUrl($params) . '/api/v1', self::token($params), $transport !== null ? $transport : new CurlTransport()));
    }

    /**
     * The panel's URL from the WHMCS server: https://<hostname or IP>[:port].
     *
     * @param array<string, mixed> $params
     */
    public static function baseUrl(array $params): string
    {
        $host = isset($params['serverhostname']) && trim((string) $params['serverhostname']) !== ''
            ? trim((string) $params['serverhostname'])
            : trim(isset($params['serverip']) ? (string) $params['serverip'] : '');
        $host = preg_replace('#^https?://#i', '', rtrim($host, '/'));
        $secure = !isset($params['serversecure']) || ($params['serversecure'] !== '' && $params['serversecure'] !== false && $params['serversecure'] !== 'off');
        $scheme = $secure ? 'https' : 'http';
        $port = isset($params['serverport']) ? (int) $params['serverport'] : 0;
        $defaultPort = $secure ? 443 : 80;

        if (strpos((string) $host, ':') === false && $port > 0 && $port !== $defaultPort) {
            $host .= ':' . $port;
        }

        return $scheme . '://' . $host;
    }

    /**
     * The API token: the WHMCS server's password field, or its access hash
     * unless that holds the webhook signing secret (whsec_…).
     *
     * @param array<string, mixed> $params
     */
    private static function token(array $params): string
    {
        $password = isset($params['serverpassword']) ? trim((string) $params['serverpassword']) : '';
        $accessHash = trim(isset($params['serveraccesshash']) ? (string) $params['serveraccesshash'] : '');

        return $password !== '' || WebhookReceiver::isSecret($accessHash) ? $password : $accessHash;
    }

    public function client(): ApiClient
    {
        return $this->client;
    }

    public function lang(): Lang
    {
        return $this->lang;
    }

    /**
     * @return array{success: bool, error: string}
     */
    public function testConnection(): array
    {
        $this->client->packages();
        $this->client->servers();

        return ['success' => true, 'error' => ''];
    }

    /**
     * CreateAccount: order the server; installing it runs in Proxmotion. A
     * repeated call for a service that already has a server changes nothing.
     */
    public function create(): string
    {
        if ($this->server() !== null) {
            return 'success';
        }

        $order = new ServerOrder($this->params);
        $payload = $order->payload($this->packageFor($order));

        if (isset($payload['root_password'])) {
            $this->client->addSecret((string) $payload['root_password']);
        }

        $task = $this->client->createServer($payload, $this->idempotencyKey('create'));

        // Without a usable WHMCS password, Proxmotion made one: keep it on the service.
        if (!isset($payload['root_password']) && isset($task['server']['id'])) {
            $this->storePassword($this->client->fetchPassword((string) $task['server']['id']));
        }

        return 'success';
    }

    public function suspend(): string
    {
        $server = $this->requireServer();

        if ($server['status'] === 'suspended') {
            return 'success';
        }

        $reason = isset($this->params['suspendreason']) && trim((string) $this->params['suspendreason']) !== '' ? trim((string) $this->params['suspendreason']) : null;
        $this->client->suspend((string) $server['id'], $reason, $this->idempotencyKey('suspend'));

        return 'success';
    }

    public function unsuspend(): string
    {
        $server = $this->requireServer();

        if ($server['status'] === 'active') {
            return 'success';
        }

        $this->client->unsuspend((string) $server['id'], $this->idempotencyKey('unsuspend'));

        return 'success';
    }

    /**
     * TerminateAccount: delete the server. Nothing left to delete is fine.
     */
    public function terminate(): string
    {
        $server = $this->server();

        if ($server === null || $server['status'] === 'terminating') {
            return 'success';
        }

        $this->client->terminate((string) $server['id'], $this->idempotencyKey('terminate'));

        return 'success';
    }

    /**
     * ChangePassword: set the password WHMCS now has for the service.
     */
    public function changePassword(): string
    {
        $password = isset($this->params['password']) ? (string) $this->params['password'] : '';

        if (strlen($password) < 12) {
            return $this->lang->get('password_too_short');
        }

        $server = $this->requireActiveServer();
        $this->client->resetPassword((string) $server['id'], $password);

        return 'success';
    }

    /**
     * ChangePackage (upgrade/downgrade): the new product's package and the
     * configurable options, with the same rules as ordering. CPU and memory
     * take effect at the server's next restart unless the product restarts it.
     */
    public function changePackage(): string
    {
        $server = $this->requireActiveServer();
        $order = new ServerOrder($this->params);
        $payload = $order->changePayload($this->packageFor($order));

        $this->client->changeResources((string) $server['id'], $payload, $this->idempotencyKey('change'));

        return 'success';
    }

    /**
     * The product's package from Proxmotion, when extra_* options need its values.
     *
     * @return array<string, mixed>|null
     */
    private function packageFor(ServerOrder $order): ?array
    {
        if (!$order->needsPackage() || $order->packageId() === null) {
            return null;
        }

        foreach ($this->client->packages() as $candidate) {
            if (($candidate['id'] ?? null) === $order->packageId()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param string $action start, shutdown, reboot or stop
     */
    public function power(string $action): string
    {
        $server = $this->requireActiveServer();
        $this->client->power((string) $server['id'], $action);

        return 'success';
    }

    /**
     * A one-time console link of the service's server, for a redirect.
     */
    public function consoleUrl(): string
    {
        $server = $this->requireActiveServer();

        return $this->client->consoleLink((string) $server['id']);
    }

    /**
     * A new random root password, stored on the WHMCS service once set.
     */
    public function resetPassword(): string
    {
        $server = $this->requireActiveServer();
        $task = $this->client->resetPassword((string) $server['id'], null);

        if ($this->waitFor($task)) {
            $this->storePassword($this->client->fetchPassword((string) $server['id']));
        }

        return 'success';
    }

    /**
     * @param array<string, mixed> $input os_template, ssh_key, confirm (from the form)
     */
    public function reinstall(array $input): string
    {
        if (empty($input['confirm'])) {
            return $this->lang->get('confirm_required');
        }

        $server = $this->requireActiveServer();
        $sshKey = isset($input['ssh_key']) ? trim((string) $input['ssh_key']) : '';
        $osTemplate = isset($input['os_template']) ? (string) $input['os_template'] : '';

        $this->client->reinstall((string) $server['id'], $osTemplate, $sshKey === '' ? [] : [$sshKey]);

        return 'success';
    }

    /**
     * Rename the server; the service's domain in WHMCS follows.
     *
     * @param array<string, mixed> $input hostname, reboot (from the form)
     */
    public function changeHostname(array $input): string
    {
        $server = $this->requireActiveServer();
        $hostname = isset($input['hostname']) ? strtolower(trim((string) $input['hostname'])) : '';

        if ($hostname === '') {
            return $this->lang->get('hostname_required');
        }

        $this->client->changeHostname((string) $server['id'], $hostname, !empty($input['reboot']), $this->idempotencyKey('hostname'));

        if (function_exists('localAPI')) {
            localAPI('UpdateClientProduct', ['serviceid' => $this->params['serviceid'], 'domain' => $hostname]);
        }

        return 'success';
    }

    /**
     * @param array<string, mixed> $input address, hostname (from the form)
     */
    public function setRdns(array $input): string
    {
        $server = $this->requireActiveServer();
        $hostname = isset($input['hostname']) ? trim((string) $input['hostname']) : '';

        $this->client->setRdns((string) $server['id'], isset($input['address']) ? (string) $input['address'] : '', $hostname === '' ? null : $hostname);

        return 'success';
    }

    /**
     * The client area overview: server details and actions.
     *
     * @return array<string, mixed>
     */
    public function clientArea(): array
    {
        $vars = [
            'lang' => $this->lang->all(),
            'server' => null,
            'error' => null,
            'osTemplates' => [],
        ];

        try {
            $found = $this->server();

            if ($found === null) {
                $vars['error'] = $this->lang->get('not_found');
            } else {
                $server = $this->client->server((string) $found['id']);

                if (!empty($server['root_password_available'])) {
                    $this->storePassword($this->client->fetchPassword((string) $server['id']));
                }

                $vars['server'] = $this->present($server);
                $vars['osTemplates'] = $server['status'] === 'active' ? $this->client->osTemplates() : [];
            }
        } catch (ApiException $e) {
            $vars['error'] = $this->lang->get('unavailable');
        }

        return [
            'tabOverviewReplacementTemplate' => 'templates/overview.tpl',
            'templateVariables' => ['pmx' => $vars],
        ];
    }

    /**
     * Fields on the service page of the WHMCS admin area (English).
     *
     * @return array<string, string>
     */
    public function adminServiceFields(): array
    {
        try {
            $found = $this->server();
        } catch (ApiException $e) {
            return ['Proxmotion' => htmlspecialchars($e->describe())];
        }

        if ($found === null) {
            return ['Proxmotion' => 'No server for this service.'];
        }

        $server = $this->client->server((string) $found['id']);
        $url = self::baseUrl($this->params) . '/admin/servers/' . rawurlencode((string) $server['id']);
        $addresses = [];

        foreach ($this->addressesOf($server) as $address) {
            $addresses[] = htmlspecialchars($address['address'] . ($address['ptr'] !== null ? ' (' . $address['ptr'] . ')' : ''));
        }

        return [
            'Proxmotion server' => '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars((string) $server['hostname']) . '</a>',
            'Server status' => htmlspecialchars((string) $server['status'] . (isset($server['power_state']) ? ' (' . $server['power_state'] . ')' : '')),
            'IP addresses' => $addresses === [] ? '—' : implode('<br>', $addresses),
        ];
    }

    /**
     * The service's live server (not terminated), looked up once per call.
     *
     * @return array<string, mixed>|null
     */
    public function server(): ?array
    {
        if ($this->server === false) {
            $this->server = $this->client->findServer((string) $this->params['serviceid']);
        }

        return $this->server;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireServer(): array
    {
        $server = $this->server();

        if ($server === null) {
            throw new ApiException($this->lang->get('not_found'));
        }

        return $server;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireActiveServer(): array
    {
        $server = $this->requireServer();

        if ($server['status'] !== 'active') {
            throw new ApiException($this->lang->get('not_active'));
        }

        return $server;
    }

    /**
     * Poll a task until it ends (at most RESET_WAIT_SECONDS). True when it succeeded.
     *
     * @param array<string, mixed> $task
     */
    private function waitFor(array $task): bool
    {
        $waited = 0;

        while (isset($task['status']) && ($task['status'] === 'queued' || $task['status'] === 'running')) {
            if ($waited >= self::RESET_WAIT_SECONDS) {
                return false;
            }

            call_user_func($this->sleep, 2);
            $waited += 2;
            $task = $this->client->task((string) $task['id']);
        }

        return isset($task['status']) && $task['status'] === 'succeeded';
    }

    /**
     * Keep a root password from Proxmotion on the WHMCS service (WHMCS stores it encrypted).
     */
    private function storePassword(?string $password): void
    {
        if ($password === null || !function_exists('localAPI')) {
            return;
        }

        localAPI('UpdateClientProduct', ['serviceid' => $this->params['serviceid'], 'servicepassword' => $password]);
    }

    /**
     * A unique key per operation: a retry after a lost connection is recognised by Proxmotion.
     */
    private function idempotencyKey(string $operation): string
    {
        return 'whmcs-' . $this->params['serviceid'] . '-' . $operation . '-' . bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function present(array $server): array
    {
        $resources = isset($server['resources']) && is_array($server['resources']) ? $server['resources'] : [];
        $status = (string) $server['status'];
        $power = isset($server['power_state']) && is_string($server['power_state']) ? $server['power_state'] : null;

        return [
            'id' => $server['id'],
            'hostname' => $server['hostname'],
            'status' => $status,
            'statusLabel' => $this->lang->get('status_' . $status),
            'active' => $status === 'active',
            'busy' => in_array($status, ['pending', 'provisioning', 'reinstalling', 'terminating'], true),
            'rebootRequired' => !empty($server['reboot_required']),
            'power' => $power,
            'powerLabel' => $this->lang->get($power === 'running' || $power === 'stopped' ? 'power_' . $power : 'power_unknown'),
            'os' => isset($server['os_template']['name']) ? $server['os_template']['name'] : null,
            'location' => isset($server['location']['name']) ? $server['location']['name'] : null,
            'cpu' => $this->lang->get('cpu_cores', ['count' => isset($resources['cpu_cores']) ? (int) $resources['cpu_cores'] : 0]),
            'memory' => $this->lang->get('memory', ['size' => self::size(isset($resources['memory_mib']) ? (int) $resources['memory_mib'] : 0)]),
            'disk' => $this->lang->get('disk', ['size' => self::size(isset($resources['disk_mib']) ? (int) $resources['disk_mib'] : 0)]),
            'addresses' => $this->addressesOf($server),
        ];
    }

    /**
     * @param array<string, mixed> $server
     * @return list<array{address: string, version: int, ptr: string|null}>
     */
    private function addressesOf(array $server): array
    {
        $addresses = [];

        foreach (isset($server['addresses']) && is_array($server['addresses']) ? $server['addresses'] : [] as $address) {
            if (is_array($address) && isset($address['address'])) {
                $addresses[] = [
                    'address' => (string) $address['address'],
                    'version' => isset($address['version']) ? (int) $address['version'] : 4,
                    'ptr' => isset($address['ptr']) && is_string($address['ptr']) ? $address['ptr'] : null,
                ];
            }
        }

        return $addresses;
    }

    /**
     * MiB as "512 MB", "4 GB" or "1.5 TB".
     */
    public static function size(int $mib): string
    {
        if ($mib >= 1024 * 1024) {
            return rtrim(rtrim(number_format($mib / 1024 / 1024, 1, '.', ''), '0'), '.') . ' TB';
        }

        if ($mib >= 1024) {
            return rtrim(rtrim(number_format($mib / 1024, 1, '.', ''), '0'), '.') . ' GB';
        }

        return $mib . ' MB';
    }
}
