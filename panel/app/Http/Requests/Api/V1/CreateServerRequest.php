<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Servers\PasswordGenerator;
use App\Enums\NetworkModel;
use App\Models\ApiToken;
use App\Models\AppRecipe;
use App\Models\BillingSystem;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Provisioning\HostnameGenerator;
use App\Provisioning\NewServer;
use App\Provisioning\ServerSpec;
use App\Rules\Hostname;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/servers. Resources come from the package; "resources" may
 * override them (configurable options of the billing system) within the
 * limits of the admin form. Without a package, "resources" gives CPU, memory
 * and disk (one IPv4 address unless given) and "network_model" is required.
 */
class CreateServerRequest extends FormRequest
{
    private const MAX_SSH_KEYS = 20;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The service id: unique within the token's billing system; it is how the module finds the server again.
            'external_id' => ['required', 'string', 'max:191', Rule::unique('servers', 'live_external_id')->where('billing_system_id', $this->billingSystemId())],
            'external_client_id' => ['nullable', 'string', 'max:191'],
            // Without a package, the resources below are given in full (custom resources).
            'package' => ['nullable', 'string', Rule::exists('packages', 'uuid')->where('is_active', true)],
            'location' => ['required', 'string', Rule::exists('locations', 'uuid')],
            'os_template' => ['required', 'string', Rule::exists('os_templates', 'uuid')->where('is_active', true)],
            // Default: the first network model the package allows; required without a package.
            'network_model' => ['nullable', 'required_without:package', Rule::enum(NetworkModel::class)->except([NetworkModel::Nat])],
            // Empty: generated from the location's hostname template (docs/adr/0031).
            'hostname' => ['nullable', 'string', 'max:253', new Hostname],
            // Empty: the next free address of the location's pools.
            'primary_ipv4' => ['nullable', 'ipv4'],
            // Empty: a random password, fetched once through GET /servers/{id}/password.
            'root_password' => ['nullable', 'string', 'min:12', 'max:128'],
            'ssh_keys' => ['nullable', 'array', 'max:'.self::MAX_SSH_KEYS],
            'ssh_keys.*' => ['string', 'max:16384', new SshPublicKeys],
            'resources' => ['nullable', 'array'],
            'resources.cpu_cores' => ['nullable', 'required_without:package', 'integer', 'between:1,512'],
            'resources.memory_mib' => ['nullable', 'required_without:package', 'integer', 'between:128,4194304'],
            'resources.disk_mib' => ['nullable', 'required_without:package', 'integer', 'between:1024,67108864'],
            // Without a package: one IPv4 address unless given.
            'resources.ipv4_count' => ['nullable', 'integer', 'between:0,64'],
            'resources.ipv6_count' => ['nullable', 'integer', 'between:0,64'],
            'resources.port_speed_mbps' => ['nullable', 'integer', 'between:1,400000'],
            'resources.traffic_bytes' => ['nullable', 'integer', 'between:1,10000000000000000'],
            // An app installed once the server has booted (docs/adr/0016 §5); eligibility and inputs are checked by the creator.
            'app' => ['nullable', 'string', Rule::exists('app_recipes', 'slug')],
            'app_inputs' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $package = $this->package();

            if ($package !== null && ! $package->network_models->contains($this->networkModel())) {
                $validator->errors()->add('network_model', __('This package does not allow the selected network model.'));
            }

            if (! $this->filled('hostname') && ($problem = HostnameGenerator::missingHostname($this->string('location')->toString())) !== null) {
                $validator->errors()->add('hostname', $problem);
            }

            $spec = $this->spec();

            if ($spec->ipv4Count + $spec->ipv6Count === 0) {
                $validator->errors()->add('resources.ipv4_count', __('A server needs at least one IP address.'));
            }

            // docs/adr/0040: a package may name the OS templates its servers use.
            $template = $package === null ? null : OsTemplate::query()->where('uuid', $this->string('os_template'))->first();

            if ($template !== null && ! $package->allowsOsTemplate($template)) {
                $validator->errors()->add('os_template', __('This package may not use the chosen OS template.'));
            }
        }];
    }

    public function toNewServer(BillingSystem $billingSystem): NewServer
    {
        return new NewServer(
            location: Location::query()->where('uuid', $this->string('location'))->firstOrFail(),
            spec: $this->spec(),
            osTemplate: OsTemplate::query()->where('uuid', $this->string('os_template'))->firstOrFail(),
            networkModel: $this->networkModel(),
            hostname: $this->filled('hostname') ? $this->string('hostname')->lower()->toString() : null,
            rootPassword: $this->filled('root_password') ? $this->string('root_password')->toString() : app(PasswordGenerator::class)->generate(),
            package: $this->package(),
            sshKeys: PublicKey::implode($this->sshKeys()),
            externalId: $this->string('external_id')->trim()->toString(),
            externalClientId: $this->filled('external_client_id') ? $this->string('external_client_id')->trim()->toString() : null,
            billingSystem: $billingSystem,
            primaryIpv4: $this->filled('primary_ipv4') ? $this->string('primary_ipv4')->trim()->toString() : null,
            app: $this->filled('app') ? AppRecipe::query()->with('latestVersion')->where('slug', $this->string('app'))->firstOrFail() : null,
            appInputs: $this->filled('app') ? (array) $this->input('app_inputs', []) : [],
        );
    }

    /**
     * @return list<PublicKey>
     */
    public function sshKeys(): array
    {
        $keys = [];

        foreach ((array) $this->input('ssh_keys', []) as $line) {
            $keys = [...$keys, ...PublicKey::parseMany((string) $line)];
        }

        return $keys;
    }

    private function billingSystemId(): int
    {
        $token = $this->attributes->get('api_token');

        return $token instanceof ApiToken ? $token->billing_system_id : abort(401);
    }

    /**
     * The chosen package, or null for custom resources.
     */
    private function package(): ?Package
    {
        return $this->filled('package') ? Package::query()->where('uuid', $this->string('package'))->firstOrFail() : null;
    }

    private function networkModel(): NetworkModel
    {
        if ($this->filled('network_model')) {
            return NetworkModel::from($this->string('network_model')->toString());
        }

        return $this->package()?->network_models->first(fn (NetworkModel $model) => $model !== NetworkModel::Nat) ?? NetworkModel::Bridged;
    }

    private function spec(): ServerSpec
    {
        $package = $this->package();
        // Without a package, missing values are the defaults of a custom server (validation requires CPU, memory and disk).
        $package = $package !== null ? ServerSpec::fromPackage($package) : new ServerSpec(cpuCores: 0, memoryMib: 0, diskMib: 0, ipv4Count: 1, ipv6Count: 0);
        $override = fn (string $key, ?int $default): ?int => $this->filled("resources.{$key}") ? $this->integer("resources.{$key}") : $default;

        return new ServerSpec(
            cpuCores: (int) $override('cpu_cores', $package->cpuCores),
            memoryMib: (int) $override('memory_mib', $package->memoryMib),
            diskMib: (int) $override('disk_mib', $package->diskMib),
            ipv4Count: (int) $override('ipv4_count', $package->ipv4Count),
            ipv6Count: (int) $override('ipv6_count', $package->ipv6Count),
            portSpeedMbps: $override('port_speed_mbps', $package->portSpeedMbps),
            trafficBytes: $override('traffic_bytes', $package->trafficBytes),
            // From the package; the API takes no overrides (docs/adr/0034).
            limits: $package->limits,
        );
    }
}
