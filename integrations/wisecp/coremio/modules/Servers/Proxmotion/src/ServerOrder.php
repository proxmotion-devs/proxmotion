<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

/**
 * Turns a WISECP service into a POST /servers payload: the product's module
 * settings (package, location, OS template, network model, resources),
 * overridden by the customer's order answers (requirements) and increased by
 * add-ons (extra_* values), and the app chosen with the order. See README.md.
 */
final class ServerOrder
{
    /** The "Package" setting for custom resources from the product settings only. */
    public const NO_PACKAGE = 'none';

    /** Resource values; the factor converts to the API's unit. */
    public const RESOURCES = [
        'cpu_cores' => ['cpu_cores', 1],
        'memory_mib' => ['memory_mib', 1],
        'memory_gib' => ['memory_mib', 1024],
        'disk_mib' => ['disk_mib', 1],
        'disk_gib' => ['disk_mib', 1024],
        'ipv4_count' => ['ipv4_count', 1],
        'ipv6_count' => ['ipv6_count', 1],
        'port_speed_mbps' => ['port_speed_mbps', 1],
        'traffic_gb' => ['traffic_bytes', 1000000000],
        // How many snapshots and backups the server may have (docs/adr/0051).
        'snapshots' => ['snapshot_slots', 1],
        'backups' => ['backup_slots', 1],
    ];

    /**
     * @param array<string, mixed> $settings the product's module settings (creation_info)
     * @param array<string, mixed> $requirements order answers mapped to the module: os_template, location, hostname, ssh_key, root_password
     * @param array<string, mixed> $addons add-on values: extra_cpu_cores, extra_memory_gib, …
     * @param string $hostname the service's hostname or domain, if any
     * @param string|null $password the root password WISECP already has for the service
     */
    public function __construct(
        private readonly array $settings,
        private readonly array $requirements,
        private readonly array $addons,
        private readonly string $serviceId,
        private readonly ?string $clientId,
        private readonly string $hostname = '',
        private readonly ?string $password = null,
    ) {}

    /**
     * @param array<string, mixed>|null $package the package from GET /packages, needed for extra_* add-ons
     * @param bool $locationNamesServers whether the location names servers without a hostname (GET /locations: generates_hostnames)
     * @return array<string, mixed>
     */
    public function payload(?array $package, bool $locationNamesServers = false): array
    {
        $payload = [
            'external_id' => $this->serviceId,
            'external_client_id' => $this->clientId,
            'package' => $this->packageId(),
            'location' => $this->location(),
            'os_template' => $this->choice('os_template'),
        ];

        $hostname = $this->hostname($locationNamesServers);

        if ($hostname !== null) {
            $payload['hostname'] = $hostname;
        }

        $networkModel = $this->setting('network_model');

        if ($networkModel !== '' && $networkModel !== 'default') {
            $payload['network_model'] = $networkModel;
        } elseif ($payload['package'] === null) {
            // Without a package Proxmotion needs the model; bridged is the usual one.
            $payload['network_model'] = 'bridged';
        }

        if ($this->rootPassword() !== null) {
            $payload['root_password'] = $this->rootPassword();
        }

        $sshKey = trim((string) ($this->requirements['ssh_key'] ?? ''));

        if ($sshKey !== '') {
            $payload['ssh_keys'] = [$sshKey];
        }

        $resources = $this->resources($package);

        if ($resources !== []) {
            $payload['resources'] = $resources;
        }

        return $payload;
    }

    /**
     * A POST /servers/{id}/package payload for an upgrade or downgrade: the
     * product's package (or none) and its resources. Values not given come
     * from the package, or without one stay as they are.
     *
     * @param array<string, mixed>|null $package
     * @return array<string, mixed>
     */
    public function changePayload(?array $package): array
    {
        $payload = ['package' => $this->packageId()];
        $resources = $this->resources($package);

        if ($resources !== []) {
            $payload['resources'] = $resources;
        }

        // Restart right away so CPU and memory apply at once.
        $payload['reboot'] = in_array($this->setting('reboot_after_upgrade'), ['1', 'on', 'yes', 'true'], true);

        return $payload;
    }

    /**
     * The app chosen with the order (order answer "app", ADR 0016 §5 in the
     * Proxmotion repository), or null: empty, "none" and "0" mean no app.
     */
    public function app(): ?string
    {
        $app = strtolower(trim((string) ($this->requirements['app'] ?? '')));

        return in_array($app, ['', 'none', '0'], true) ? null : $app;
    }

    /**
     * The app's inputs from the order answer "app_inputs", one "key=value" per
     * line. Empty values are left out so the app's defaults apply; with the
     * app's declared inputs, other keys are left out too.
     *
     * @param list<string>|null $declared the input keys of the chosen app, if known
     * @return array<string, string>
     */
    public function appInputs(?array $declared): array
    {
        $inputs = [];

        foreach (preg_split('/\R/', (string) ($this->requirements['app_inputs'] ?? '')) ?: [] as $line) {
            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = [trim($parts[0]), trim($parts[1])];

            if ($key !== '' && $value !== '' && ($declared === null || in_array($key, $declared, true))) {
                $inputs[$key] = $value;
            }
        }

        return $inputs;
    }

    /**
     * Paid apps bought as add-ons (add-on value "paid_app", ADR 0019): app ids,
     * several separated by commas or spaces.
     *
     * @return list<string>
     */
    public function paidApps(): array
    {
        $apps = preg_split('/[\s,]+/', strtolower((string) ($this->addons['paid_app'] ?? ''))) ?: [];

        return array_values(array_unique(array_filter($apps, static fn (string $app): bool => preg_match('/^[a-z0-9][a-z0-9-]*$/', $app) === 1)));
    }

    /**
     * The OS template of the order: the customer's answer or the product's.
     */
    public function osTemplate(): string
    {
        return $this->choice('os_template');
    }

    /**
     * The package from the product settings, or null for custom resources.
     */
    public function packageId(): ?string
    {
        $package = $this->setting('package');

        return $package === '' || $package === self::NO_PACKAGE ? null : $package;
    }

    /**
     * Whether add-ons change resources relative to the package, so the package's values are needed.
     */
    public function needsPackage(): bool
    {
        foreach (array_keys(self::RESOURCES) as $name) {
            if (is_numeric($this->addons['extra_' . $name] ?? null) && (float) $this->addons['extra_' . $name] != 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The root password: the customer's order answer or the one WISECP has,
     * if long enough for Proxmotion (12 to 128 characters); otherwise
     * Proxmotion makes one and the module fetches it.
     */
    public function rootPassword(): ?string
    {
        foreach ([(string) ($this->requirements['root_password'] ?? ''), (string) $this->password] as $password) {
            if (strlen($password) >= 12 && strlen($password) <= 128) {
                return $password;
            }
        }

        return null;
    }

    /**
     * The customer's hostname, else the service's, if valid. Otherwise null
     * when the location names the server itself (its hostname template,
     * docs/adr/0031), else "vps<service id>".
     */
    public function hostname(bool $locationNamesServers = false): ?string
    {
        return $this->givenHostname() ?? ($locationNamesServers ? null : 'vps' . $this->serviceId);
    }

    /**
     * The customer's hostname, else the service's, if valid.
     */
    public function givenHostname(): ?string
    {
        foreach ([(string) ($this->requirements['hostname'] ?? ''), $this->hostname] as $candidate) {
            $candidate = rtrim(strtolower(trim($candidate)), '.');

            if (self::isHostname($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The customer's location, else the product's.
     */
    public function location(): string
    {
        return $this->choice('location');
    }

    public static function isHostname(string $value): bool
    {
        return $value !== '' && strlen($value) <= 253
            && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $value) === 1;
    }

    /**
     * @param array<string, mixed>|null $package
     * @return array<string, int>
     */
    private function resources(?array $package): array
    {
        $resources = [];

        foreach (self::RESOURCES as $name => [$target, $factor]) {
            $value = $this->settings[$name] ?? null;

            if (is_numeric($value)) {
                $resources[$target] = (int) round((float) $value * $factor);
            }
        }

        foreach (self::RESOURCES as $name => [$target, $factor]) {
            $extra = $this->addons['extra_' . $name] ?? null;

            if (! is_numeric($extra) || (float) $extra == 0.0) {
                continue;
            }

            $base = $resources[$target] ?? $this->packageValue($package, $target);
            $resources[$target] = $base + (int) round((float) $extra * $factor);
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
                ? 'Add-ons with extra_ values add to a package: choose a package in the product settings, or set the resource there.'
                : 'The package of this product was not found in Proxmotion.');
        }

        return is_numeric($package[$key] ?? null) ? (int) $package[$key] : 0;
    }

    /**
     * The customer's choice of a location or OS template, else the product's.
     */
    private function choice(string $name): string
    {
        $answer = $this->requirements[$name] ?? null;

        return is_string($answer) && trim($answer) !== '' ? trim($answer) : $this->setting($name);
    }

    private function setting(string $name): string
    {
        $value = $this->settings[$name] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
