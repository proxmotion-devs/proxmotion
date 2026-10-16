<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\NetworkModel;
use App\Models\ApiToken;
use App\Models\BillingSystem;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Provisioning\NewServer;
use App\Provisioning\ServerSpec;
use App\Rules\Hostname;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/servers. Resources come from the package; "resources" may
 * override them (configurable options of the billing system) within the
 * limits of the admin form.
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
            'external_id' => ['required', 'string', 'max:191', Rule::unique('servers', 'external_id')->where('billing_system_id', $this->billingSystemId())],
            'external_client_id' => ['nullable', 'string', 'max:191'],
            'package' => ['required', 'string', Rule::exists('packages', 'uuid')->where('is_active', true)],
            'location' => ['required', 'string', Rule::exists('locations', 'uuid')],
            'os_template' => ['required', 'string', Rule::exists('os_templates', 'uuid')->where('is_active', true)],
            // Default: the first network model the package allows.
            'network_model' => ['nullable', Rule::enum(NetworkModel::class)->except([NetworkModel::Nat])],
            'hostname' => ['required', 'string', 'max:253', new Hostname],
            // Empty: a random password, fetched once through GET /servers/{id}/password.
            'root_password' => ['nullable', 'string', 'min:12', 'max:128'],
            'ssh_keys' => ['nullable', 'array', 'max:'.self::MAX_SSH_KEYS],
            'ssh_keys.*' => ['string', 'max:16384', new SshPublicKeys],
            'resources' => ['nullable', 'array'],
            'resources.cpu_cores' => ['nullable', 'integer', 'between:1,512'],
            'resources.memory_mib' => ['nullable', 'integer', 'between:128,4194304'],
            'resources.disk_mib' => ['nullable', 'integer', 'between:1024,67108864'],
            'resources.ipv4_count' => ['nullable', 'integer', 'between:0,64'],
            'resources.ipv6_count' => ['nullable', 'integer', 'between:0,64'],
            'resources.port_speed_mbps' => ['nullable', 'integer', 'between:1,400000'],
            'resources.traffic_bytes' => ['nullable', 'integer', 'between:1,10000000000000000'],
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

            if (! $package->network_models->contains($this->networkModel())) {
                $validator->errors()->add('network_model', __('This package does not allow the selected network model.'));
            }

            $spec = $this->spec();

            if ($spec->ipv4Count + $spec->ipv6Count === 0) {
                $validator->errors()->add('resources.ipv4_count', __('A server needs at least one IP address.'));
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
            hostname: $this->string('hostname')->lower()->toString(),
            rootPassword: $this->filled('root_password') ? $this->string('root_password')->toString() : Str::password(20, symbols: false),
            package: $this->package(),
            sshKeys: PublicKey::implode($this->sshKeys()),
            externalId: $this->string('external_id')->trim()->toString(),
            externalClientId: $this->filled('external_client_id') ? $this->string('external_client_id')->trim()->toString() : null,
            billingSystem: $billingSystem,
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

    private function package(): Package
    {
        return Package::query()->where('uuid', $this->string('package'))->firstOrFail();
    }

    private function networkModel(): NetworkModel
    {
        if ($this->filled('network_model')) {
            return NetworkModel::from($this->string('network_model')->toString());
        }

        return $this->package()->network_models->first(fn (NetworkModel $model) => $model !== NetworkModel::Nat) ?? NetworkModel::Bridged;
    }

    private function spec(): ServerSpec
    {
        $package = ServerSpec::fromPackage($this->package());
        $override = fn (string $key, ?int $default): ?int => $this->filled("resources.{$key}") ? $this->integer("resources.{$key}") : $default;

        return new ServerSpec(
            cpuCores: (int) $override('cpu_cores', $package->cpuCores),
            memoryMib: (int) $override('memory_mib', $package->memoryMib),
            diskMib: (int) $override('disk_mib', $package->diskMib),
            ipv4Count: (int) $override('ipv4_count', $package->ipv4Count),
            ipv6Count: (int) $override('ipv6_count', $package->ipv6Count),
            portSpeedMbps: $override('port_speed_mbps', $package->portSpeedMbps),
            trafficBytes: $override('traffic_bytes', $package->trafficBytes),
        );
    }
}
