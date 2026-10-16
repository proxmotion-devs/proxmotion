<?php

namespace Proxmotion\Whmcs;

/**
 * Turns a WHMCS service into a POST /servers payload: the product's module
 * settings (package, location, OS template, network model), overridden by
 * configurable options and the "ssh_key" custom field (see README.md).
 */
class ServerOrder
{
    /** The "Package" setting for custom resources from configurable options only. */
    const NO_PACKAGE = 'none';

    /** Configurable options that replace a module setting (value: an id from Proxmotion). */
    const CHOICES = ['location', 'os_template', 'network_model'];

    /** Configurable options that set a resource; the factor converts to the API's unit. */
    const RESOURCES = [
        'cpu_cores' => ['cpu_cores', 1],
        'memory_mib' => ['memory_mib', 1],
        'memory_gib' => ['memory_mib', 1024],
        'disk_mib' => ['disk_mib', 1],
        'disk_gib' => ['disk_mib', 1024],
        'ipv4_count' => ['ipv4_count', 1],
        'ipv6_count' => ['ipv6_count', 1],
        'port_speed_mbps' => ['port_speed_mbps', 1],
        'traffic_gb' => ['traffic_bytes', 1000000000],
    ];

    /** @var array<string, mixed> */
    private $params;

    /**
     * @param array<string, mixed> $params the WHMCS module parameters
     */
    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * @param array<string, mixed>|null $package the package from GET /packages, needed for extra_* options
     * @return array<string, mixed>
     */
    public function payload(?array $package): array
    {
        $payload = [
            'external_id' => (string) $this->params['serviceid'],
            'external_client_id' => isset($this->params['userid']) ? (string) $this->params['userid'] : null,
            'package' => $this->packageId(),
            'location' => $this->choice('location', $this->setting(2)),
            'os_template' => $this->choice('os_template', $this->setting(3)),
            'hostname' => $this->hostname(),
        ];

        $networkModel = $this->choice('network_model', $this->setting(4));

        if ($networkModel !== '' && $networkModel !== 'default') {
            $payload['network_model'] = $networkModel;
        } elseif ($payload['package'] === null) {
            // Without a package Proxmotion needs the model; bridged is the usual one.
            $payload['network_model'] = 'bridged';
        }

        if ($this->rootPassword() !== null) {
            $payload['root_password'] = $this->rootPassword();
        }

        $keys = $this->sshKeys();

        if ($keys !== []) {
            $payload['ssh_keys'] = $keys;
        }

        $resources = $this->resources($package);

        if ($resources !== []) {
            $payload['resources'] = $resources;
        }

        return $payload;
    }

    /**
     * The package from the module settings, or null for custom resources.
     */
    public function packageId(): ?string
    {
        $package = $this->setting(1);

        return $package === '' || $package === self::NO_PACKAGE ? null : $package;
    }

    /**
     * A POST /servers/{id}/package payload for ChangePackage: the product's
     * package (or none) and the resources set by configurable options. Values
     * not given come from the package, or without one stay as they are.
     *
     * @param array<string, mixed>|null $package the package from GET /packages, needed for extra_* options
     * @return array<string, mixed>
     */
    public function changePayload(?array $package): array
    {
        $payload = ['package' => $this->packageId()];
        $resources = $this->resources($package);

        if ($resources !== []) {
            $payload['resources'] = $resources;
        }

        // Module setting 5 (yes/no): restart right away so CPU and memory apply at once.
        $payload['reboot'] = $this->setting(5) === 'on' || $this->setting(5) === '1' || $this->setting(5) === 'yes';

        return $payload;
    }

    /**
     * Whether the order changes resources relative to the package ("extra_*"
     * options), so the package's values are needed.
     */
    public function needsPackage(): bool
    {
        foreach (array_keys($this->configOptions()) as $name) {
            if (strpos($name, 'extra_') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The WHMCS service password, if long enough for Proxmotion (12+
     * characters); otherwise Proxmotion makes one and the module fetches it.
     */
    public function rootPassword(): ?string
    {
        $password = isset($this->params['password']) ? (string) $this->params['password'] : '';

        return strlen($password) >= 12 && strlen($password) <= 128 ? $password : null;
    }

    /**
     * The service's domain if it is a valid hostname, else "vps<service id>".
     */
    public function hostname(): string
    {
        $domain = strtolower(trim(isset($this->params['domain']) ? (string) $this->params['domain'] : ''));
        $domain = rtrim($domain, '.');

        if ($domain !== '' && strlen($domain) <= 253 && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $domain) === 1) {
            return $domain;
        }

        return 'vps' . $this->params['serviceid'];
    }

    /**
     * @return list<string>
     */
    private function sshKeys(): array
    {
        $fields = isset($this->params['customfields']) && is_array($this->params['customfields']) ? $this->params['customfields'] : [];
        $value = isset($fields['ssh_key']) ? trim((string) $fields['ssh_key']) : '';

        return $value === '' ? [] : [$value];
    }

    /**
     * @param array<string, mixed>|null $package
     * @return array<string, int>
     */
    private function resources(?array $package): array
    {
        $resources = [];
        $options = $this->configOptions();

        foreach (self::RESOURCES as $name => $target) {
            if (isset($options[$name]) && is_numeric($options[$name])) {
                $resources[$target[0]] = (int) round((float) $options[$name] * $target[1]);
            }
        }

        foreach (self::RESOURCES as $name => $target) {
            $extra = 'extra_' . $name;

            if (!isset($options[$extra]) || !is_numeric($options[$extra]) || (float) $options[$extra] == 0.0) {
                continue;
            }

            $base = isset($resources[$target[0]]) ? $resources[$target[0]] : $this->packageValue($package, $target[0]);
            $resources[$target[0]] = $base + (int) round((float) $options[$extra] * $target[1]);
        }

        return $resources;
    }

    /**
     * @param array<string, mixed>|null $package
     */
    private function packageValue(?array $package, string $key): int
    {
        if ($package === null) {
            throw new ApiException($this->packageId() === null
                ? 'Configurable options starting with extra_ add to a package: choose a package in the module settings, or use options without extra_.'
                : 'The package of this product was not found in Proxmotion.');
        }

        return isset($package[$key]) && is_numeric($package[$key]) ? (int) $package[$key] : 0;
    }

    private function choice(string $name, string $default): string
    {
        $options = $this->configOptions();

        return isset($options[$name]) && is_string($options[$name]) && trim($options[$name]) !== '' ? trim($options[$name]) : $default;
    }

    private function setting(int $number): string
    {
        $key = 'configoption' . $number;

        return isset($this->params[$key]) ? trim((string) $this->params[$key]) : '';
    }

    /**
     * Configurable options by name ("cpu_cores|CPU cores" arrives as "cpu_cores").
     *
     * @return array<string, mixed>
     */
    private function configOptions(): array
    {
        return isset($this->params['configoptions']) && is_array($this->params['configoptions']) ? $this->params['configoptions'] : [];
    }
}
