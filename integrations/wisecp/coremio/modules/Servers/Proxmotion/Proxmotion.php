<?php

declare(strict_types=1);

namespace WISECP\Modules\Servers;

use Closure;
use Exception;
use Proxmotion\Wisecp\ApiClient;
use Proxmotion\Wisecp\ApiException;
use Proxmotion\Wisecp\CurlTransport;
use Proxmotion\Wisecp\ServerOrder;
use Proxmotion\Wisecp\Transport;
use Proxmotion\Wisecp\WebhookReceiver;
use Proxmotion\Wisecp\WisecpWebhookStore;
use ServerModule;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * The Proxmotion server module for WISECP 5. WISECP orders, suspends and
 * terminates VPS on Proxmotion; customers manage their server with WISECP's
 * server tools. The module is a thin client of the Proxmotion billing API v1:
 * business logic stays in Proxmotion.
 */
class Proxmotion extends ServerModule
{
    /** Waits for a password reset to finish before WISECP stores the new password. */
    public const RESET_WAIT_SECONDS = 20;

    /** Server statuses during which Proxmotion is still working on the server. */
    private const BUSY = ['pending', 'provisioning', 'reinstalling', 'terminating'];

    /** Tools that need a running server, hidden while it is busy or not active. */
    private const SERVER_TOOLS = ['start-server', 'stop-server', 'restart-server', 'poweroff-server', 'reset-password', 'reinstall-os', 'vnc-console', 'hostname-change', 'reverse-dns', 'apps'];

    /** App installation statuses during which the installer still runs. */
    private const APP_BUSY = ['queued', 'installing', 'uninstalling', 'upgrading'];

    /** Reference of the paid app rights this module grants for add-ons (ADR 0019). */
    private const ADDON_REFERENCE = 'wisecp-addon';

    private ApiClient $api;

    /** @var array<string, mixed>|null|false false: not looked up yet */
    private array|null|false $found = false;

    /**
     * @param array<string, mixed>|int $server
     * @param array<string, mixed> $options
     */
    public function __construct($server = [], $options = [])
    {
        // The server's state changes while Proxmotion installs it: always show the current one.
        $this->dashboard_cache = false;
        parent::__construct($server, $options);
    }

    /**
     * @param array<string, mixed> $server
     */
    protected function define_server_info(array $server = []): void
    {
        $this->api = new ApiClient(
            self::baseUrl($server) . '/api/v1',
            self::token($server),
            $this->transport(),
            $this->logger(),
        );
        $this->found = false;
    }

    /**
     * The App Marketplace is a tool of the module's own (WISECP has none):
     * its page is pages/tools/apps.php.
     */
    public function configure_features(): void
    {
        $this->add_tool('apps', [
            'name' => $this->text('tool_apps'),
            'label' => $this->text('tool_apps'),
            'group' => 'system',
            'order' => 15,
            'icon' => 'bi bi-grid',
            'page' => 'apps',
            'type' => 'page-loader',
            'capabilities' => ['list', 'install', 'retry', 'download', 'uninstall', 'upgrade', 'action'],
        ]);
    }

    /**
     * App inputs arrive as JSON in secret_inputs, unchanged (passwords may hold
     * any character): WISECP masks fields named *secret* in the service history.
     *
     * @return array<string, array<string, string|null>>
     */
    protected function get_tool_action_data_filters(): array
    {
        $filters = parent::get_tool_action_data_filters();
        $filters['apps'] = ['secret_inputs' => null, 'app' => 'route', 'installation' => 'route', 'run' => 'route', 'app_action' => 'identifier', 'output' => 'identifier'];

        return $filters;
    }

    protected function transport(): Transport
    {
        return new CurlTransport;
    }

    /**
     * API calls go to WISECP's module log (Tools → Module logs).
     *
     * @return Closure(string, string, string): void
     */
    private function logger(): Closure
    {
        return function (string $action, string $request, string $response): void {
            $this->save_log($action, $request, $response);
        };
    }

    /**
     * The panel's URL from the WISECP server: https://<IP or hostname>[:port].
     *
     * @param array<string, mixed> $server
     */
    public static function baseUrl(array $server): string
    {
        $host = trim((string) ($server['ip'] ?? ''));
        $host = $host !== '' && $host !== '0.0.0.0' ? $host : trim((string) ($server['name'] ?? ''));
        $host = (string) preg_replace('#^https?://#i', '', rtrim($host, '/'));
        $secure = ! isset($server['secure']) || (bool) $server['secure'];
        $port = (int) ($server['port'] ?? 0);

        if (! str_contains($host, ':') && $port > 0 && $port !== ($secure ? 443 : 80)) {
            $host .= ':' . $port;
        }

        return ($secure ? 'https' : 'http') . '://' . $host;
    }

    /**
     * The API token: the server's password field, decrypted by WISECP.
     *
     * @param array<string, mixed> $server
     */
    public static function token(array $server): string
    {
        return trim((string) ($server['password'] ?? ''));
    }

    /**
     * A field of the server form (config.php), decrypted by WISECP.
     *
     * @param array<string, mixed> $server
     */
    public static function field(array $server, string $name): string
    {
        $fields = is_array($server['fields'] ?? null) ? $server['fields'] : [];

        return trim((string) ($fields[$name] ?? ''));
    }

    public function test_connect(): bool
    {
        $this->api->packages();
        $this->api->servers();

        return true;
    }

    /**
     * The product's Module tab. The lists come from Proxmotion; without a
     * connection the ids can still be typed in.
     *
     * @param array<string, mixed> $data the saved values
     * @return array<string, array<string, mixed>>
     */
    public function product_configuration(array $data = []): array
    {
        try {
            $packages = [ServerOrder::NO_PACKAGE => $this->text('no_package')] + $this->choices($this->api->packages());
            $locations = $this->choices($this->api->locations());
            $osTemplates = $this->choices($this->api->osTemplates());
            $unavailable = null;
        } catch (ApiException $e) {
            $packages = $locations = $osTemplates = null;
            $unavailable = $this->text('lists_unavailable', ['error' => $e->describe()]);
        }

        $choice = fn (string $key, ?array $options): array => $options === null
            ? ['name' => $this->text('setting_' . $key), 'description' => $unavailable, 'type' => 'text', 'value' => (string) ($data[$key] ?? ''), 'wrap_width' => 'col-lg-6']
            : ['name' => $this->text('setting_' . $key), 'type' => 'dropdown', 'options' => $options, 'value' => (string) ($data[$key] ?? ''), 'advanced_selector' => true, 'wrap_width' => 'col-lg-6'];

        $fields = [
            'package' => $choice('package', $packages),
            'location' => $choice('location', $locations),
            'os_template' => $choice('os_template', $osTemplates),
            'network_model' => $choice('network_model', [
                'default' => $this->text('network_default'),
                'bridged' => $this->text('network_bridged'),
                'routed' => $this->text('network_routed'),
            ]),
            'reboot_after_upgrade' => [
                'name' => $this->text('setting_reboot_after_upgrade'),
                'description' => $this->text('setting_reboot_after_upgrade_desc'),
                'type' => 'approval',
                'checked' => in_array((string) ($data['reboot_after_upgrade'] ?? ''), ['1', 'on', 'yes', 'true'], true),
            ],
        ];

        foreach (['cpu_cores', 'memory_gib', 'disk_gib', 'ipv4_count', 'ipv6_count', 'port_speed_mbps', 'traffic_gb'] as $resource) {
            $fields[$resource] = [
                'name' => $this->text('setting_' . $resource),
                'description' => $this->text('setting_resource_desc'),
                'type' => 'text',
                'value' => (string) ($data[$resource] ?? ''),
                'wrap_width' => 'col-lg-3',
            ];
        }

        return $fields;
    }

    /**
     * Order the server; installing it runs in Proxmotion and the client area
     * shows the progress. A repeated call for a service that already has a
     * server orders nothing.
     *
     * @return array<string, mixed>
     */
    public function create(): array
    {
        $existing = $this->found();
        $order = $this->order((array) ($this->options['creation_info'] ?? []));

        if ($existing !== null) {
            $this->syncPaidApps((string) $existing['id'], $order, revoke: false);

            return $this->created((string) $existing['id'], null);
        }

        $payload = $order->payload($this->packageFor($order));
        $app = $order->app();

        if ($app !== null) {
            // Installed once the server has booted (ADR 0016 §5); Proxmotion checks the app and its inputs.
            $payload['app'] = $app;
            $payload['app_inputs'] = (object) $order->appInputs($this->declaredInputs($app, $order->osTemplate()));
        }

        if (isset($payload['root_password'])) {
            $this->api->addSecret((string) $payload['root_password']);
        }

        $task = $this->api->createServer($payload, $this->idempotencyKey('create'));
        $serverId = (string) ($task['server']['id'] ?? '');

        if ($serverId === '') {
            throw new Exception($this->text('no_server_returned'));
        }

        $this->syncPaidApps($serverId, $order, revoke: false);

        // Without a usable password Proxmotion made one: keep it on the service.
        return $this->created($serverId, $payload['root_password'] ?? $this->api->fetchPassword($serverId));
    }

    public function suspend(): bool
    {
        $server = $this->requireServer();

        if ($server['status'] !== 'suspended') {
            $reason = trim((string) ($this->service['suspended_reason'] ?? ''));
            $this->api->suspend((string) $server['id'], $reason !== '' ? $reason : null, $this->idempotencyKey('suspend'));
        }

        return true;
    }

    public function unsuspend(): bool
    {
        $server = $this->requireServer();

        if ($server['status'] !== 'active') {
            $this->api->unsuspend((string) $server['id'], $this->idempotencyKey('unsuspend'));
        }

        return true;
    }

    /**
     * Terminate (WISECP's cancel): delete the server. Nothing left to delete is fine.
     */
    public function cancel(): bool
    {
        $server = $this->found();

        if ($server !== null && $server['status'] !== 'terminating') {
            $this->api->terminate((string) $server['id'], $this->idempotencyKey('terminate'));
        }

        return true;
    }

    /**
     * Upgrade or downgrade to another product: its package and resources, with
     * the same rules as ordering. CPU and memory take effect at the server's
     * next restart unless the product restarts it.
     *
     * @param array<string, mixed> $new_product
     */
    public function upgrade(array $new_product = []): bool
    {
        $server = $this->requireActiveServer();
        $order = $this->order(is_array($new_product['module_data'] ?? null) ? $new_product['module_data'] : []);

        $this->api->changeResources((string) $server['id'], $order->changePayload($this->packageFor($order)), $this->idempotencyKey('change'));
        // Add-ons changed with the upgrade: paid apps bought or cancelled (ADR 0019).
        $this->syncPaidApps((string) $server['id'], $order);

        return true;
    }

    /**
     * Paid apps bought as add-ons (add-on value "paid_app", ADR 0019): the
     * server gets the right to each, and loses the rights this module granted
     * for add-ons that are gone (installed copies stay). Rights from the
     * order or from administrators are left alone.
     *
     * @param bool $revoke false for a new server, which has no add-on rights to revoke yet
     */
    private function syncPaidApps(string $serverId, ServerOrder $order, bool $revoke = true): void
    {
        $wanted = $order->paidApps();

        if ($wanted === [] && ! $revoke) {
            return;
        }
        $active = [];

        foreach ($this->api->entitlements($serverId) as $entitlement) {
            if (empty($entitlement['active'])) {
                continue;
            }

            $app = (string) ($entitlement['app']['id'] ?? '');
            $active[$app] = true;

            if (($entitlement['reference'] ?? null) === self::ADDON_REFERENCE && ! in_array($app, $wanted, true)) {
                $this->api->revokeEntitlement($serverId, $app);
            }
        }

        foreach ($wanted as $app) {
            if (! isset($active[$app])) {
                $this->api->grantEntitlement($serverId, $app, self::ADDON_REFERENCE);
            }
        }
    }

    /**
     * The input keys an app declares for an order with this OS template, or
     * null when the catalog does not list it (Proxmotion then explains why).
     *
     * @return list<string>|null
     */
    private function declaredInputs(string $app, string $osTemplate): ?array
    {
        foreach ($this->api->orderApps($osTemplate === '' ? null : $osTemplate) as $listed) {
            if (($listed['id'] ?? null) === $app) {
                return array_values(array_map(
                    static fn (array $input): string => (string) ($input['key'] ?? ''),
                    array_filter(is_array($listed['inputs'] ?? null) ? $listed['inputs'] : [], 'is_array'),
                ));
            }
        }

        return null;
    }

    /**
     * Set the root password (also the client area's password reset: WISECP
     * stores the password on the service once this returns).
     */
    public function change_password(string $password): bool
    {
        if (strlen($password) < 12 || strlen($password) > 128) {
            throw new Exception($this->text('password_length'));
        }

        $server = $this->requireActiveServer();
        $task = $this->api->resetPassword((string) $server['id'], $password);

        // Still running after the wait counts as set: the task rarely fails late.
        if ($this->waitFor($task) === 'failed') {
            throw new Exception($this->text('password_not_set'));
        }

        return true;
    }

    /**
     * The service page: status, addresses and resources.
     *
     * @return array<string, array<string, mixed>>
     */
    public function dashboard_data(): array
    {
        $server = $this->api->server((string) $this->requireServer()['id']);

        if (! empty($server['root_password_available'])) {
            $password = $this->api->fetchPassword((string) $server['id']);

            if ($password !== null) {
                $this->save_login_password($password);
            }
        }

        $resources = is_array($server['resources'] ?? null) ? $server['resources'] : [];
        $addresses = $this->addressesOf($server);
        $ipv4 = array_values(array_filter($addresses, fn (array $address): bool => $address['version'] === 4));
        $status = (string) $server['status'];
        $power = is_string($server['power_state'] ?? null) ? $server['power_state'] : 'unknown';
        $shown = $status === 'active' ? $power : $status;

        return [
            'server_info' => [
                'hostname' => ['value' => (string) $server['hostname']],
                'ip_address' => ['value' => $ipv4[0]['address'] ?? ($addresses[0]['address'] ?? ''), 'copyable' => true],
                'os' => $this->os_info_item((string) ($server['os_template']['name'] ?? '')),
                'virtualization' => ['value' => 'KVM'],
                'location' => $this->location_info_item((string) ($server['location']['name'] ?? '')),
                'status' => $this->vm_status_item($shown, [
                    'badge_color' => match (true) {
                        $shown === 'running' => 'success',
                        in_array($shown, ['stopped', 'error', 'suspended'], true) => 'danger',
                        default => 'warning',
                    },
                    'effect' => $shown === 'running' ? 'online' : '',
                ]),
            ],
            'resource_allocation' => [
                'cpu_cores' => ['value' => $this->text('cpu_cores', ['count' => (int) ($resources['cpu_cores'] ?? 0)])],
                'ram' => ['value' => self::size((int) ($resources['memory_mib'] ?? 0))],
                'disk_space' => ['value' => self::size((int) ($resources['disk_mib'] ?? 0))],
                'ipv4' => ['value' => (string) count($ipv4)],
                'ipv6' => ['value' => (string) (count($addresses) - count($ipv4))],
            ],
        ];
    }

    /**
     * Only the actions that fit the server's state.
     *
     * @param array<string, mixed> $data
     */
    public function configure_dashboard_tools(array $data): void
    {
        $status = $this->vm_status_of($data);

        if ($status === 'running') {
            $this->remove_tool('start-server');
        } elseif ($status === 'stopped') {
            $this->remove_tool('stop-server');
            $this->remove_tool('restart-server');
            $this->remove_tool('poweroff-server');
        } else {
            foreach (self::SERVER_TOOLS as $tool) {
                $this->remove_tool($tool);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function tool_data(string $tool, string $action, array $params = []): array
    {
        if ($tool === 'apps') {
            return $this->appsData((string) $this->requireActiveServer()['id']);
        }

        $server = $this->api->server((string) $this->requireActiveServer()['id']);

        return match ($tool) {
            'reinstall-os' => [
                'templates' => array_map(static fn (array $template): array => [
                    'slug' => (string) $template['id'],
                    'name' => (string) ($template['name'] ?? $template['id']),
                    'category' => (string) ($template['family'] ?? 'other'),
                ], $this->api->osTemplates()),
                'current_os' => (string) ($server['os_template']['name'] ?? ''),
                // Proxmotion makes the new root password; it appears on the service afterwards.
                'password_supported' => false,
            ],
            'reverse-dns' => ['items' => array_map(static fn (array $address): array => [
                'ip' => $address['address'],
                'hostname' => (string) $address['ptr'],
                'version' => $address['version'] === 6 ? 'ipv6' : 'ipv4',
            ], $this->addressesOf($server))],
            'hostname-change' => ['hostname' => (string) $server['hostname']],
            'vnc-console' => ['url' => '', 'console_type' => 'vnc'],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function tool_action(string $tool, string $action, array $data = []): array
    {
        $id = (string) $this->requireActiveServer()['id'];

        return match ($tool) {
            'start-server' => $this->power($id, 'start', 'start'),
            'stop-server' => $this->power($id, 'shutdown', 'stop'),
            'restart-server' => $this->power($id, 'reboot', 'restart'),
            'poweroff-server' => $this->power($id, 'stop', 'poweroff'),
            'reinstall-os' => $this->reinstall($id, trim((string) ($data['os_template'] ?? '')), null),
            'reverse-dns' => $this->rdns($id, trim((string) ($data['ip'] ?? '')), trim((string) ($data['hostname'] ?? ''))),
            'hostname-change' => $this->rename($id, strtolower(trim((string) ($data['hostname'] ?? '')))),
            // A signed, single-use link to Proxmotion's console, opened in a new tab.
            'vnc-console' => ['status' => 'successful', 'redirect' => $this->api->consoleLink($id)],
            'apps' => match ($action) {
                'install' => $this->installApp($id, trim((string) ($data['app'] ?? '')), (string) ($data['secret_inputs'] ?? '')),
                'retry' => $this->retryApp($id, trim((string) ($data['installation'] ?? ''))),
                // Reinstall with an app (ADR 0016 §5): WISECP's own reinstall tool has no place for one.
                'reinstall' => empty($data['confirm'])
                    ? throw new Exception($this->text('reinstall_confirm_required'))
                    : $this->reinstall($id, trim((string) ($data['os_template'] ?? '')), trim((string) ($data['app'] ?? ''))),
                'download' => $this->appFile($id, trim((string) ($data['installation'] ?? '')), trim((string) ($data['output'] ?? '')), trim((string) ($data['run'] ?? ''))),
                'uninstall' => $this->uninstallApp($id, trim((string) ($data['installation'] ?? ''))),
                'upgrade' => $this->upgradeApp($id, trim((string) ($data['installation'] ?? '')), (string) ($data['secret_inputs'] ?? '')),
                // The field is app_action: WISECP removes "action" from the data it passes on.
                'action' => $this->runAppAction($id, trim((string) ($data['installation'] ?? '')), trim((string) ($data['app_action'] ?? '')), (string) ($data['secret_inputs'] ?? '')),
                default => throw new Exception('Unknown action: ' . $action),
            },
            default => throw new Exception('Unknown tool: ' . $tool),
        };
    }

    /**
     * POST /api/v1/proxmotion/webhook (hooks.php): a webhook from Proxmotion.
     *
     * @param array<string, mixed> $match
     */
    public function api_webhook(\WISECP\Api\Core\Request $request, array $match): \WISECP\Api\Core\Response
    {
        $receiver = new WebhookReceiver(new WisecpWebhookStore, fn (array $server): ApiClient => new ApiClient(
            self::baseUrl($server) . '/api/v1',
            self::token($server),
            $this->transport(),
            $this->logger(),
        ));
        $result = $receiver->handle($request->method, $request->headers, $request->rawBody);

        return new \WISECP\Api\Core\Response($result['status'], $result['status'] < 400
            ? ['data' => ['message' => $result['body']]]
            : ['error' => ['code' => 'webhook_not_processed', 'message' => $result['body']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function power(string $id, string $action, string $message): array
    {
        $this->api->power($id, $action);

        return ['status' => 'successful', 'message' => \Language::gc('system/module/action-success-' . $message . '-server'), 'successToast' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function reinstall(string $id, string $osTemplate, ?string $app): array
    {
        $allowed = array_map(static fn (array $template): string => (string) $template['id'], $this->api->osTemplates());

        if (! in_array($osTemplate, $allowed, true)) {
            throw new Exception($this->text('unknown_os_template'));
        }

        $this->api->reinstall($id, $osTemplate, [], $app === null || $app === '' ? null : $app);

        return ['status' => 'successful', 'message' => $this->text('reinstall_started'), 'successToast' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function rdns(string $id, string $address, string $hostname): array
    {
        $this->api->setRdns($id, $address, $hostname === '' ? null : $hostname);

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function rename(string $id, string $hostname): array
    {
        if (! ServerOrder::isHostname($hostname)) {
            throw new Exception($this->text('hostname_invalid'));
        }

        $this->api->changeHostname($id, $hostname, false, $this->idempotencyKey('hostname'));

        // WISECP keeps the new name on the service (after_tool_action).
        return [];
    }

    /**
     * The apps page: installations (outputs fetched now, never stored in
     * WISECP) and the apps that fit the server.
     *
     * @return array{installations: list<array<string, mixed>>, apps: list<array<string, mixed>>, installing: bool}
     */
    private function appsData(string $id): array
    {
        $installations = [];

        foreach ($this->api->installations($id) as $installation) {
            // Uninstalls and actions with their outputs (ADR 0016), fetched now like the install outputs.
            $runs = ($installation['status'] ?? null) === 'removed' ? [] : $this->api->appRuns($id, (string) ($installation['id'] ?? ''));
            $installations[] = $this->presentInstallation($installation, $runs);
        }

        $busy = array_filter($installations, static fn (array $installation): bool => $installation['busy']) !== [];

        return [
            'installations' => $installations,
            // One app installs at a time per server.
            'apps' => $busy ? [] : array_map(fn (array $app): array => $this->presentApp($app), $this->api->apps($id)),
            // Never a top-level "busy" key: WISECP then shows its "operation in progress" notice instead of the page.
            'installing' => $busy,
            'reinstall' => $this->reinstallOptions($id),
        ];
    }

    /**
     * The Apps tool's reinstall form (ADR 0016 §5): the OS templates, and the
     * apps that fit the server, that it may install (paid ones need a right)
     * and that need no input without a default, since the form only picks
     * the app. Without the order catalog (an older panel) no apps are offered.
     *
     * @return array{templates: list<array{id: string, name: string}>, apps: list<array{id: string, name: string}>}
     */
    private function reinstallOptions(string $id): array
    {
        $templates = array_map(static fn (array $template): array => ['id' => (string) $template['id'], 'name' => (string) ($template['name'] ?? $template['id'])], $this->api->osTemplates());

        try {
            $listed = $this->api->orderApps(null, $id);
        } catch (ApiException) {
            $listed = [];
        }

        $apps = [];

        foreach ($listed as $app) {
            $needsInput = array_filter(
                is_array($app['inputs'] ?? null) ? $app['inputs'] : [],
                static fn (mixed $input): bool => is_array($input) && ! empty($input['required']) && in_array($input['default'] ?? null, [null, ''], true),
            ) !== [];

            if (($app['entitled'] ?? true) !== false && ! $needsInput) {
                $apps[] = ['id' => (string) $app['id'], 'name' => (string) ($app['name'] ?? $app['id'])];
            }
        }

        return ['templates' => $templates, 'apps' => $apps];
    }

    /**
     * Install an app; empty inputs are left out so the app's defaults apply.
     * Proxmotion validates the values against the recipe.
     *
     * @return array<string, mixed>
     */
    private function installApp(string $id, string $app, string $inputsJson): array
    {
        if ($app === '') {
            throw new Exception($this->text('app_required'));
        }

        $this->api->installApp($id, $app, $this->decodeInputs($inputsJson), $this->idempotencyKey('app'));

        return ['status' => 'successful', 'message' => $this->text('app_install_started'), 'successToast' => true];
    }

    /**
     * Run the app's uninstall script (ADR 0016).
     *
     * @return array<string, mixed>
     */
    private function uninstallApp(string $id, string $installation): array
    {
        if ($installation === '') {
            throw new Exception($this->text('app_not_found'));
        }

        $this->api->uninstallApp($id, $installation, $this->idempotencyKey('app-uninstall'));

        return ['status' => 'successful', 'message' => $this->text('app_uninstall_started'), 'successToast' => true];
    }

    /**
     * Upgrade an app to its newer version with the inputs that version adds (ADR 0016).
     *
     * @return array<string, mixed>
     */
    private function upgradeApp(string $id, string $installation, string $inputsJson): array
    {
        if ($installation === '') {
            throw new Exception($this->text('app_not_found'));
        }

        $this->api->upgradeApp($id, $installation, $this->decodeInputs($inputsJson), $this->idempotencyKey('app-upgrade'));

        return ['status' => 'successful', 'message' => $this->text('app_upgrade_started'), 'successToast' => true];
    }

    /**
     * Run an action of an installed app (e.g. add a VPN client) with its inputs.
     *
     * @return array<string, mixed>
     */
    private function runAppAction(string $id, string $installation, string $action, string $inputsJson): array
    {
        if ($installation === '') {
            throw new Exception($this->text('app_not_found'));
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $action) !== 1) {
            throw new Exception($this->text('app_action_required'));
        }

        $this->api->runAppAction($id, $installation, $action, $this->decodeInputs($inputsJson), $this->idempotencyKey('app-action'));

        return ['status' => 'successful', 'message' => $this->text('app_action_started'), 'successToast' => true];
    }

    /**
     * Inputs sent as JSON in secret_inputs; empty values are left out so the defaults apply.
     *
     * @return array<string, string>
     */
    private function decodeInputs(string $inputsJson): array
    {
        $decoded = $inputsJson === '' ? [] : json_decode($inputsJson, true);

        if (! is_array($decoded)) {
            throw new Exception($this->text('app_inputs_invalid'));
        }

        $inputs = [];

        foreach ($decoded as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $inputs[(string) $key] = trim((string) $value);
            }
        }

        return $inputs;
    }

    /**
     * @return array<string, mixed>
     */
    private function retryApp(string $id, string $installation): array
    {
        if ($installation === '') {
            throw new Exception($this->text('app_not_found'));
        }

        $this->api->retryApp($id, $installation, $this->idempotencyKey('app-retry'));

        return ['status' => 'successful', 'message' => $this->text('app_install_started'), 'successToast' => true];
    }

    /**
     * A file output of an installation, fetched from Proxmotion when the
     * customer downloads it; the page turns it into a download.
     *
     * @return array<string, mixed>
     */
    private function appFile(string $id, string $installation, string $output, string $run = ''): array
    {
        if ($installation === '') {
            throw new Exception($this->text('app_not_found'));
        }

        // A file of an action run (e.g. a new VPN client), or of the installation.
        $data = $run === '' ? $this->api->installation($id, $installation) : $this->api->appRun($id, $installation, $run);

        foreach (is_array($data['outputs'] ?? null) ? $data['outputs'] : [] as $item) {
            if (! is_array($item) || ($item['key'] ?? null) !== $output || ! is_string($item['content_base64'] ?? null) || ! is_string($item['filename'] ?? null)) {
                continue;
            }

            if (base64_decode($item['content_base64'], true) === false) {
                break;
            }

            return [
                'status' => 'successful',
                'file' => [
                    'name' => (string) preg_replace('/[^A-Za-z0-9._-]/', '', $item['filename']),
                    'content_base64' => $item['content_base64'],
                ],
            ];
        }

        throw new Exception($this->text('app_file_missing'));
    }

    /**
     * An installable app with its form inputs, texts in the customer's language.
     *
     * @param array<string, mixed> $app
     * @return array<string, mixed>
     */
    private function presentApp(array $app): array
    {
        return [
            'id' => (string) ($app['id'] ?? ''),
            'name' => (string) ($app['name'] ?? ''),
            'description' => $this->recipeText($app['description'] ?? null, ''),
            'inputs' => $this->presentInputs(is_array($app['inputs'] ?? null) ? $app['inputs'] : []),
            // ADR 0019: a paid app the server has no right to yet is offered to buy (as an add-on) instead.
            'buy' => ($app['entitled'] ?? null) === false,
            'licence_required' => ($app['license'] ?? null) === 'external',
        ];
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
            if (! is_array($input) || ! isset($input['key'], $input['type'])) {
                continue;
            }

            $default = $input['default'] ?? null;

            $inputs[] = [
                'key' => (string) $input['key'],
                'type' => (string) $input['type'],
                'label' => $this->recipeText($input['label'] ?? null, (string) $input['key']),
                'required' => ! empty($input['required']),
                'default' => is_bool($default) ? ($default ? '1' : '0') : (is_scalar($default) ? (string) $default : ''),
                'options' => is_array($input['options'] ?? null) ? array_values(array_map('strval', array_filter($input['options'], 'is_scalar'))) : [],
                'min' => isset($input['min']) && is_numeric($input['min']) ? (int) $input['min'] : null,
                'max' => isset($input['max']) && is_numeric($input['max']) ? (int) $input['max'] : null,
            ];
        }

        return $inputs;
    }

    /**
     * An installation with its status, outputs (texts shown, files downloaded
     * on demand), what can be done with it and its latest runs (ADR 0016).
     *
     * @param array<string, mixed> $installation
     * @param list<array<string, mixed>> $runs
     * @return array<string, mixed>
     */
    private function presentInstallation(array $installation, array $runs = []): array
    {
        $status = (string) ($installation['status'] ?? '');
        $actions = [];

        foreach (is_array($installation['actions'] ?? null) ? $installation['actions'] : [] as $action) {
            if (is_array($action) && isset($action['key'])) {
                $actions[] = [
                    'key' => (string) $action['key'],
                    'label' => $this->recipeText($action['label'] ?? null, (string) $action['key']),
                    'inputs' => $this->presentInputs(is_array($action['inputs'] ?? null) ? $action['inputs'] : []),
                ];
            }
        }

        $presentedRuns = array_map(fn (array $run): array => $this->presentRun($run, $actions), $runs);

        return [
            'id' => (string) ($installation['id'] ?? ''),
            'name' => is_array($installation['app'] ?? null) ? (string) ($installation['app']['name'] ?? '') : '',
            'status' => $status,
            'status_label' => $this->text('app_status_' . $status),
            'busy' => in_array($status, self::APP_BUSY, true),
            'failed' => $status === 'failed',
            'danger' => in_array($status, ['failed', 'uninstall_failed', 'upgrade_failed'], true),
            'progress' => (int) ($installation['progress'] ?? 0),
            'step' => is_string($installation['step'] ?? null) ? $installation['step'] : null,
            'error' => is_string($installation['error'] ?? null) ? $installation['error'] : null,
            'outputs' => $this->presentOutputs($installation),
            'can_uninstall' => ! empty($installation['can_uninstall']),
            'upgrade' => $this->presentUpgrade($installation),
            'actions' => $actions,
            'runs' => $presentedRuns,
            'run_busy' => array_filter($presentedRuns, static fn (array $run): bool => $run['busy']) !== [],
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
        $upgrade = is_array($installation['upgrade'] ?? null) ? $installation['upgrade'] : null;

        if ($upgrade === null || ! isset($upgrade['version'])) {
            return null;
        }

        return [
            'version' => (string) $upgrade['version'],
            'label' => $this->text('app_upgrade_to', ['version' => (string) $upgrade['version']]),
            'inputs' => $this->presentInputs(is_array($upgrade['inputs'] ?? null) ? $upgrade['inputs'] : []),
        ];
    }

    /**
     * An uninstall, upgrade or action run with its status and outputs.
     *
     * @param array<string, mixed> $run
     * @param list<array{key: string, label: string, inputs: list<array<string, mixed>>}> $actions the installation's actions (for their labels)
     * @return array<string, mixed>
     */
    private function presentRun(array $run, array $actions): array
    {
        $status = (string) ($run['status'] ?? '');
        $label = $this->text('app_uninstall');

        if (($run['kind'] ?? null) === 'upgrade') {
            $label = $this->text('app_upgrade_to', ['version' => (string) ($run['target_version'] ?? '')]);
        }

        if (($run['kind'] ?? null) === 'action') {
            $label = (string) ($run['action'] ?? '');

            foreach ($actions as $action) {
                if ($action['key'] === $label) {
                    $label = $action['label'];
                }
            }
        }

        return [
            'id' => (string) ($run['id'] ?? ''),
            'label' => $label,
            'status' => $status,
            'status_label' => $this->text('app_run_status_' . $status),
            'busy' => in_array($status, ['queued', 'running'], true),
            'failed' => $status === 'failed',
            'error' => is_string($run['error'] ?? null) ? $run['error'] : null,
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

        foreach (is_array($item['outputs'] ?? null) ? $item['outputs'] : [] as $output) {
            if (! is_array($output) || ! isset($output['key'])) {
                continue;
            }

            $type = (string) ($output['type'] ?? 'text');

            $outputs[] = [
                'key' => (string) $output['key'],
                'type' => in_array($type, ['text', 'url', 'file'], true) ? $type : 'text',
                'label' => $this->recipeText($output['label'] ?? null, (string) $output['key']),
                'value' => is_string($output['value'] ?? null) ? $output['value'] : null,
                'filename' => is_string($output['filename'] ?? null) ? $output['filename'] : null,
            ];
        }

        return $outputs;
    }

    /**
     * A recipe text ({en, tr, …}) in the customer's language, English otherwise.
     */
    private function recipeText(mixed $text, string $fallback): string
    {
        if (! is_array($text) || ! is_string($text['en'] ?? null)) {
            return $fallback;
        }

        $locale = $this->text('locale');

        return is_string($text[$locale] ?? null) ? $text[$locale] : $text['en'];
    }

    /**
     * What WISECP stores on the service after create.
     *
     * @return array<string, mixed>
     */
    private function created(string $serverId, ?string $password): array
    {
        $result = ['config' => ['server_id' => $serverId]];

        if ($password !== null) {
            $result['login'] = ['username' => 'root', 'password' => $this->encode_str($password)];
        }

        try {
            $server = $this->api->server($serverId);
            $addresses = array_column($this->addressesOf($server), 'address');
            $result['hostname'] = (string) $server['hostname'];
            $result['ip'] = $addresses[0] ?? '';
            $result['assigned_ips'] = implode("\n", array_slice($addresses, 1));
        } catch (ApiException) {
            // The server is ordered; its details show up in the client area.
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $settings the product's module settings
     */
    private function order(array $settings): ServerOrder
    {
        $hostname = (string) ($this->options['hostname'] ?? $this->options['domain'] ?? '');
        $password = $this->get_login_password();

        return new ServerOrder(
            $settings,
            $this->requirement_params,
            $this->addon_params,
            (string) $this->service['id'],
            isset($this->service['owner_id']) ? (string) $this->service['owner_id'] : null,
            $hostname,
            $password !== '' ? $password : null,
        );
    }

    /**
     * The product's package from Proxmotion, when extra_* add-ons need its values.
     *
     * @return array<string, mixed>|null
     */
    private function packageFor(ServerOrder $order): ?array
    {
        if (! $order->needsPackage() || $order->packageId() === null) {
            return null;
        }

        foreach ($this->api->packages() as $package) {
            if (($package['id'] ?? null) === $order->packageId()) {
                return $package;
            }
        }

        return null;
    }

    /**
     * The service's live server (not terminated), looked up once.
     *
     * @return array<string, mixed>|null
     */
    private function found(): ?array
    {
        if ($this->found === false) {
            $this->found = $this->api->findServer((string) $this->service['id']);
        }

        return $this->found;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireServer(): array
    {
        return $this->found() ?? throw new Exception($this->text('not_found'));
    }

    /**
     * @return array<string, mixed>
     */
    private function requireActiveServer(): array
    {
        $server = $this->requireServer();

        if ($server['status'] !== 'active') {
            throw new Exception($this->text(in_array($server['status'], self::BUSY, true) ? 'busy' : 'not_active'));
        }

        return $server;
    }

    /**
     * Poll a task until it ends or RESET_WAIT_SECONDS passed; its last status.
     *
     * @param array<string, mixed> $task
     */
    private function waitFor(array $task): string
    {
        $waited = 0;

        while (in_array($task['status'] ?? null, ['queued', 'running'], true) && $waited < self::RESET_WAIT_SECONDS) {
            $this->sleep(2);
            $waited += 2;
            $task = $this->api->task((string) $task['id']);
        }

        return (string) ($task['status'] ?? '');
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * A unique key per operation: a retry after a lost connection is recognised by Proxmotion.
     */
    private function idempotencyKey(string $operation): string
    {
        return 'wisecp-' . $this->service['id'] . '-' . $operation . '-' . bin2hex(random_bytes(8));
    }

    /**
     * @param list<array<string, mixed>> $items packages, locations or OS templates
     * @return array<string, string> id => name
     */
    private function choices(array $items): array
    {
        $choices = [];

        foreach ($items as $item) {
            if (isset($item['id'])) {
                $choices[(string) $item['id']] = (string) ($item['name'] ?? $item['id']);
            }
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $server
     * @return list<array{address: string, version: int, ptr: string|null}>
     */
    private function addressesOf(array $server): array
    {
        $addresses = [];

        foreach (is_array($server['addresses'] ?? null) ? $server['addresses'] : [] as $address) {
            if (is_array($address) && isset($address['address'])) {
                $addresses[] = [
                    'address' => (string) $address['address'],
                    'version' => (int) ($address['version'] ?? 4),
                    'ptr' => is_string($address['ptr'] ?? null) ? $address['ptr'] : null,
                ];
            }
        }

        return $addresses;
    }

    /**
     * A text from lang/<language>.php with :placeholders replaced.
     *
     * @param array<string, string|int> $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $text = (string) ($this->lang[$key] ?? $key);

        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
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
