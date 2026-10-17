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
    private const SERVER_TOOLS = ['start-server', 'stop-server', 'restart-server', 'poweroff-server', 'reset-password', 'reinstall-os', 'vnc-console', 'hostname-change', 'reverse-dns'];

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
            self::field($server, 'api_token'),
            $this->transport(),
            $this->logger(),
        );
        $this->found = false;
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

        if ($existing !== null) {
            return $this->created((string) $existing['id'], null);
        }

        $order = $this->order((array) ($this->options['creation_info'] ?? []));
        $payload = $order->payload($this->packageFor($order));

        if (isset($payload['root_password'])) {
            $this->api->addSecret((string) $payload['root_password']);
        }

        $task = $this->api->createServer($payload, $this->idempotencyKey('create'));
        $serverId = (string) ($task['server']['id'] ?? '');

        if ($serverId === '') {
            throw new Exception($this->text('no_server_returned'));
        }

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

        return true;
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
            'reinstall-os' => $this->reinstall($id, trim((string) ($data['os_template'] ?? ''))),
            'reverse-dns' => $this->rdns($id, trim((string) ($data['ip'] ?? '')), trim((string) ($data['hostname'] ?? ''))),
            'hostname-change' => $this->rename($id, strtolower(trim((string) ($data['hostname'] ?? '')))),
            // A signed, single-use link to Proxmotion's console, opened in a new tab.
            'vnc-console' => ['status' => 'successful', 'redirect' => $this->api->consoleLink($id)],
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
            self::field($server, 'api_token'),
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
    private function reinstall(string $id, string $osTemplate): array
    {
        $allowed = array_map(static fn (array $template): string => (string) $template['id'], $this->api->osTemplates());

        if (! in_array($osTemplate, $allowed, true)) {
            throw new Exception($this->text('unknown_os_template'));
        }

        $this->api->reinstall($id, $osTemplate, []);

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
