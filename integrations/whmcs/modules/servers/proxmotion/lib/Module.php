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
        $app = $order->app();

        if ($app !== null) {
            // Installed once the server has booted (docs/adr/0016 §5); Proxmotion checks the app and its inputs.
            $payload['app'] = $app;
            $payload['app_inputs'] = (object) $order->appInputs($this->declaredInputs($app, $order->osTemplate()));
        }

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
     * The input keys an app declares for an order with this OS template, or
     * null when the catalog does not list it (Proxmotion then explains why).
     *
     * @return list<string>|null
     */
    private function declaredInputs(string $app, string $osTemplate): ?array
    {
        foreach ($this->client->orderApps($osTemplate === '' ? null : $osTemplate) as $listed) {
            if (isset($listed['id']) && $listed['id'] === $app) {
                $keys = [];

                foreach (isset($listed['inputs']) && is_array($listed['inputs']) ? $listed['inputs'] : [] as $input) {
                    if (is_array($input) && isset($input['key'])) {
                        $keys[] = (string) $input['key'];
                    }
                }

                return $keys;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input os_template, ssh_key, app (optional, installed with its defaults), confirm (from the form)
     */
    public function reinstall(array $input): string
    {
        if (empty($input['confirm'])) {
            return $this->lang->get('confirm_required');
        }

        $server = $this->requireActiveServer();
        $sshKey = isset($input['ssh_key']) ? trim((string) $input['ssh_key']) : '';
        $osTemplate = isset($input['os_template']) ? (string) $input['os_template'] : '';
        $app = isset($input['app']) ? trim((string) $input['app']) : '';

        $this->client->reinstall((string) $server['id'], $osTemplate, $sshKey === '' ? [] : [$sshKey], $app === '' ? null : $app);

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
     * Install an app (docs/adr/0015); empty inputs are left out so the app's defaults apply.
     *
     * @param array<string, mixed> $input app, inputs[key] (from the form)
     */
    public function installApp(array $input): string
    {
        $server = $this->requireActiveServer();
        $app = isset($input['app']) ? trim((string) $input['app']) : '';

        if ($app === '') {
            return $this->lang->get('app_required');
        }

        $this->client->installApp((string) $server['id'], $app, $this->filledInputs($input), $this->idempotencyKey('app'));

        return 'success';
    }

    /**
     * The non-empty inputs[key] values of a form, trimmed.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function filledInputs(array $input): array
    {
        $values = [];

        foreach (isset($input['inputs']) && is_array($input['inputs']) ? $input['inputs'] : [] as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $values[(string) $key] = trim((string) $value);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $input installation (from the form)
     */
    public function retryApp(array $input): string
    {
        $server = $this->requireActiveServer();
        $this->client->retryApp((string) $server['id'], isset($input['installation']) ? (string) $input['installation'] : '', $this->idempotencyKey('app-retry'));

        return 'success';
    }

    /**
     * Uninstall an app (docs/adr/0016): its recipe's uninstall script runs in the server.
     *
     * @param array<string, mixed> $input installation (from the form)
     */
    public function uninstallApp(array $input): string
    {
        $server = $this->requireActiveServer();
        $this->client->uninstallApp((string) $server['id'], isset($input['installation']) ? (string) $input['installation'] : '', $this->idempotencyKey('app-uninstall'));

        return 'success';
    }


    /**
     * Upgrade an app to its newer version (docs/adr/0016); empty inputs are left out.
     *
     * @param array<string, mixed> $input installation, inputs[key] (from the form)
     */
    public function upgradeApp(array $input): string
    {
        $server = $this->requireActiveServer();
        $this->client->upgradeApp(
            (string) $server['id'],
            isset($input['installation']) ? (string) $input['installation'] : '',
            $this->filledInputs($input),
            $this->idempotencyKey('app-upgrade')
        );

        return 'success';
    }
    /**
     * Run an action of an installed app (e.g. add a VPN client); empty inputs are left out.
     *
     * @param array<string, mixed> $input installation, app_action, inputs[key] (from the form)
     */
    public function runAppAction(array $input): string
    {
        $server = $this->requireActiveServer();
        $action = isset($input['app_action']) ? trim((string) $input['app_action']) : '';

        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $action) !== 1) {
            return $this->lang->get('app_action_required');
        }

        $this->client->runAppAction(
            (string) $server['id'],
            isset($input['installation']) ? (string) $input['installation'] : '',
            $action,
            $this->filledInputs($input),
            $this->idempotencyKey('app-action')
        );

        return 'success';
    }

    /**
     * A file output of an installed app, or of one of its action runs when
     * $run is given, fetched from Proxmotion when asked for (never stored in WHMCS).
     *
     * @return array{filename: string, content: string}|null
     */
    public function appFile(string $installation, string $output, string $run = ''): ?array
    {
        $server = $this->requireServer();
        $data = $run === ''
            ? $this->client->installation((string) $server['id'], $installation)
            : $this->client->appRun((string) $server['id'], $installation, $run);

        foreach (isset($data['outputs']) && is_array($data['outputs']) ? $data['outputs'] : [] as $item) {
            if (is_array($item) && ($item['key'] ?? null) === $output && isset($item['content_base64'], $item['filename']) && is_string($item['content_base64'])) {
                $content = base64_decode($item['content_base64'], true);

                return $content === false ? null : [
                    'filename' => (string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) $item['filename']),
                    'content' => $content,
                ];
            }
        }

        return null;
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
            'apps' => [],
            'reinstallApps' => [],
            'addonsUrl' => $this->addonsUrl(),
            'installations' => [],
            'appsBusy' => false,
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

                if ($server['status'] === 'active') {
                    foreach ($this->client->installations((string) $server['id']) as $installation) {
                        // Uninstalls and actions with their outputs (docs/adr/0016), fetched now like the install outputs.
                        $runs = ($installation['status'] ?? null) === 'removed' ? [] : $this->client->appRuns((string) $server['id'], (string) $installation['id']);
                        $vars['installations'][] = $this->presentInstallation($installation, $runs);
                    }

                    $vars['apps'] = array_map([$this, 'presentApp'], $this->client->apps((string) $server['id']));
                    $vars['reinstallApps'] = $vars['osTemplates'] === [] ? [] : $this->reinstallApps((string) $server['id']);
                    $vars['appsBusy'] = count(array_filter($vars['installations'], function (array $installation) {
                        return $installation['busy'];
                    })) > 0;
                }
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
     * A product addon of this service was activated: when module setting 6
     * maps it to a paid app, the server may install that app (docs/adr/0019).
     *
     * @return string|null the app, or null when the addon is not a paid app
     */
    public function grantAddon(int $addonId, int $serviceAddonId): ?string
    {
        $addons = (new ServerOrder($this->params))->paidAppAddons();

        if (!isset($addons[$addonId])) {
            return null;
        }

        $this->client->grantEntitlement((string) $this->requireServer()['id'], $addons[$addonId], 'whmcs-addon-' . $serviceAddonId);

        return $addons[$addonId];
    }

    /**
     * A product addon of this service was cancelled or terminated: the paid
     * app can no longer be installed (an installed copy stays).
     *
     * @return string|null the app, or null when the addon is not a paid app
     */
    public function revokeAddon(int $addonId): ?string
    {
        $addons = (new ServerOrder($this->params))->paidAppAddons();

        if (!isset($addons[$addonId])) {
            return null;
        }

        $server = $this->server();

        // Without a server (terminated) there is nothing left to revoke.
        if ($server !== null) {
            $this->client->revokeEntitlement((string) $server['id'], $addons[$addonId]);
        }

        return $addons[$addonId];
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
     * An installable app with its form inputs, labels in the customer's language.
     *
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    private function presentApp(array $app): array
    {
        return [
            'id' => (string) $app['id'],
            'name' => (string) $app['name'],
            'description' => $this->text(isset($app['description']) ? $app['description'] : null, ''),
            'inputs' => $this->presentInputs(isset($app['inputs']) && is_array($app['inputs']) ? $app['inputs'] : []),
            // docs/adr/0019: a paid app the server has no right to yet is offered to buy instead.
            'paid' => isset($app['billing']) && $app['billing'] === 'paid',
            'buy' => isset($app['entitled']) && $app['entitled'] === false,
            'licence_required' => isset($app['license']) && $app['license'] === 'external',
        ];
    }

    /**
     * Apps the reinstall form offers (docs/adr/0016 §5): those that fit the
     * server, that it may install (paid ones need an entitlement) and that
     * need no input without a default, since the form only picks the app.
     *
     * @return list<array<string, mixed>>
     */
    private function reinstallApps(string $serverId): array
    {
        $apps = [];

        try {
            $listed = $this->client->orderApps(null, $serverId);
        } catch (ApiException $e) {
            // Reinstalling still works without an app (e.g. a panel before mode=order).
            return [];
        }

        foreach ($listed as $app) {
            if (isset($app['entitled']) && $app['entitled'] === false) {
                continue;
            }

            foreach (isset($app['inputs']) && is_array($app['inputs']) ? $app['inputs'] : [] as $input) {
                if (is_array($input) && !empty($input['required']) && (!isset($input['default']) || $input['default'] === '')) {
                    continue 2;
                }
            }

            $apps[] = ['id' => (string) $app['id'], 'name' => (string) $app['name']];
        }

        return $apps;
    }

    /**
     * Where the customer buys addons of this service (paid apps, docs/adr/0019).
     */
    private function addonsUrl(): string
    {
        return 'cart.php?gid=addons&pid=' . rawurlencode((string) $this->params['serviceid']);
    }

    /**
     * Form fields of declared inputs (an app's or an action's), labels in the customer's language.
     *
     * @param array<mixed> $declared
     * @return list<array<string, mixed>>
     */
    private function presentInputs(array $declared): array
    {
        $inputs = [];

        foreach ($declared as $input) {
            if (!is_array($input) || !isset($input['key'], $input['type'])) {
                continue;
            }

            $inputs[] = [
                'key' => (string) $input['key'],
                'type' => (string) $input['type'],
                'label' => $this->text(isset($input['label']) ? $input['label'] : null, (string) $input['key']),
                'required' => !empty($input['required']),
                'default' => isset($input['default']) && is_scalar($input['default']) ? (is_bool($input['default']) ? ($input['default'] ? '1' : '0') : (string) $input['default']) : '',
                'options' => isset($input['options']) && is_array($input['options']) ? array_values(array_map('strval', $input['options'])) : [],
                'min' => isset($input['min']) ? (int) $input['min'] : null,
                'max' => isset($input['max']) ? (int) $input['max'] : null,
            ];
        }

        return $inputs;
    }

    /**
     * An installation with its status, outputs (texts shown, files as download
     * links), what can be done with it and its latest runs (docs/adr/0016).
     *
     * @param array<string, mixed> $installation
     * @param list<array<string, mixed>> $runs
     * @return array<string, mixed>
     */
    private function presentInstallation(array $installation, array $runs = []): array
    {
        $status = (string) $installation['status'];
        $actions = [];

        foreach (isset($installation['actions']) && is_array($installation['actions']) ? $installation['actions'] : [] as $action) {
            if (is_array($action) && isset($action['key'])) {
                $actions[] = [
                    'key' => (string) $action['key'],
                    'label' => $this->text(isset($action['label']) ? $action['label'] : null, (string) $action['key']),
                    'inputs' => $this->presentInputs(isset($action['inputs']) && is_array($action['inputs']) ? $action['inputs'] : []),
                ];
            }
        }

        $presentedRuns = [];

        foreach ($runs as $run) {
            $presentedRuns[] = $this->presentRun($run, $actions);
        }

        return [
            'id' => (string) $installation['id'],
            'name' => isset($installation['app']['name']) ? (string) $installation['app']['name'] : '',
            'status' => $status,
            'statusLabel' => $this->lang->get('app_status_' . $status),
            'busy' => in_array($status, ['queued', 'installing', 'uninstalling', 'upgrading'], true),
            'failed' => $status === 'failed',
            'danger' => in_array($status, ['failed', 'uninstall_failed', 'upgrade_failed'], true),
            'progress' => isset($installation['progress']) ? (int) $installation['progress'] : 0,
            'error' => isset($installation['error']) && is_string($installation['error']) ? $installation['error'] : null,
            'outputs' => $this->presentOutputs($installation),
            'canUninstall' => !empty($installation['can_uninstall']),
            'upgrade' => $this->presentUpgrade($installation),
            'actions' => $actions,
            'runs' => $presentedRuns,
            'runBusy' => count(array_filter($presentedRuns, function (array $run) {
                return $run['busy'];
            })) > 0,
        ];
    }

    /**
     * The newer version the installation can be upgraded to with the inputs it adds, or null.
     *
     * @param array<string, mixed> $installation
     * @return array{version: string, label: string, inputs: list<array<string, mixed>>}|null
     */
    private function presentUpgrade(array $installation): ?array
    {
        $upgrade = isset($installation['upgrade']) && is_array($installation['upgrade']) ? $installation['upgrade'] : null;

        if ($upgrade === null || !isset($upgrade['version'])) {
            return null;
        }

        return [
            'version' => (string) $upgrade['version'],
            'label' => $this->lang->get('app_upgrade_to', ['version' => (string) $upgrade['version']]),
            'inputs' => $this->presentInputs(isset($upgrade['inputs']) && is_array($upgrade['inputs']) ? $upgrade['inputs'] : []),
        ];
    }

    /**
     * An uninstall, upgrade or action run with its status and outputs.
     *
     * @param array<string, mixed> $run
     * @param list<array<string, mixed>> $actions the installation's actions (for their labels)
     * @return array<string, mixed>
     */
    private function presentRun(array $run, array $actions): array
    {
        $status = (string) $run['status'];
        $label = $this->lang->get('app_uninstall');

        if (($run['kind'] ?? null) === 'upgrade') {
            $label = $this->lang->get('app_upgrade_to', ['version' => isset($run['target_version']) ? (string) $run['target_version'] : '']);
        }

        if (($run['kind'] ?? null) === 'action') {
            $label = isset($run['action']) ? (string) $run['action'] : '';

            foreach ($actions as $action) {
                if ($action['key'] === $label) {
                    $label = $action['label'];
                }
            }
        }

        return [
            'id' => (string) $run['id'],
            'label' => $label,
            'status' => $status,
            'statusLabel' => $this->lang->get('app_run_status_' . $status),
            'busy' => in_array($status, ['queued', 'running'], true),
            'failed' => $status === 'failed',
            'error' => isset($run['error']) && is_string($run['error']) ? $run['error'] : null,
            'outputs' => $this->presentOutputs($run),
        ];
    }

    /**
     * @param array<string, mixed> $item an installation or a run
     * @return list<array<string, mixed>>
     */
    private function presentOutputs(array $item): array
    {
        $outputs = [];

        foreach (isset($item['outputs']) && is_array($item['outputs']) ? $item['outputs'] : [] as $output) {
            if (!is_array($output) || !isset($output['key'])) {
                continue;
            }

            $outputs[] = [
                'key' => (string) $output['key'],
                'label' => $this->text(isset($output['label']) ? $output['label'] : null, (string) $output['key']),
                'file' => ($output['type'] ?? null) === 'file',
                'url' => ($output['type'] ?? null) === 'url',
                'value' => isset($output['value']) && is_string($output['value']) ? $output['value'] : null,
                'filename' => isset($output['filename']) && is_string($output['filename']) ? $output['filename'] : null,
            ];
        }

        return $outputs;
    }

    /**
     * A recipe text ({en, tr}) in the customer's language.
     *
     * @param mixed $text
     */
    private function text($text, string $fallback): string
    {
        if (!is_array($text) || !isset($text['en'])) {
            return $fallback;
        }

        $locale = $this->lang->get('locale');

        return isset($text[$locale]) && is_string($text[$locale]) ? $text[$locale] : (string) $text['en'];
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
