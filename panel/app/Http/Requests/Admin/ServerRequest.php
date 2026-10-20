<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\NetworkModel;
use App\Models\AppRecipe;
use App\Models\BillingSystem;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Node;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\PlacementGroup;
use App\Provisioning\HostnameGenerator;
use App\Provisioning\NewServer;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use App\Rules\Hostname;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ServerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $custom = $this->custom();

        return [
            // The billing system that owns the server, and the ids of the service and customer there.
            'billing_system' => ['nullable', 'required_with:external_id,external_client_id', 'string', Rule::exists('billing_systems', 'uuid')],
            'external_id' => ['nullable', 'string', 'max:191', Rule::unique('servers', 'live_external_id')->where('billing_system_id', $this->billingSystem()?->id)],
            'external_client_id' => ['nullable', 'string', 'max:191'],
            'location' => ['required', 'string', Rule::exists('locations', 'uuid')],
            // "package": the package's resources, placed automatically like an order from the
            // billing API (best node of its placement groups, or of the location without
            // groups); "custom": resources entered below, placed in a group or on one node.
            'resource_mode' => ['required', Rule::in(['package', 'custom'])],
            'package' => [Rule::requiredIf(! $custom), 'nullable', 'string', Rule::exists('packages', 'uuid')->where('is_active', true)],
            // Same limits as the package form.
            'cpu_cores' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:1,512'],
            'memory_mib' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:128,4194304'],
            'disk_gib' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:1,65536'],
            'ipv4_count' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:0,64'],
            'ipv6_count' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:0,64'],
            // Empty: unlimited.
            'port_speed_mbps' => ['nullable', 'integer', 'between:1,400000'],
            'traffic_gb' => ['nullable', 'integer', 'between:1,10000000'],
            // Custom servers only: "group", the best node of a placement group (docs/adr/0020);
            // "node", the node (and optionally storage) chosen here.
            'placement' => [Rule::requiredIf($custom), Rule::prohibitedIf(! $custom), 'nullable', Rule::in(['group', 'node'])],
            'placement_group' => ['nullable', 'required_if:placement,group', 'string', Rule::exists('placement_groups', 'uuid')],
            'node' => ['nullable', 'required_if:placement,node', 'string', Rule::exists('nodes', 'uuid')],
            // Empty: the storage of the node with the most room.
            'storage' => ['nullable', 'string', 'max:191'],
            // Inactive templates too: administrators try a template on a server before offering it (docs/adr/0024 §8).
            'os_template' => ['required', 'string', Rule::exists('os_templates', 'uuid')],
            'network_model' => ['required', Rule::enum(NetworkModel::class)->except([NetworkModel::Nat])],
            // Empty: generated from the location's hostname template (docs/adr/0031).
            'hostname' => ['nullable', 'string', 'max:253', new Hostname],
            // Empty: the first pool of the location with room for the server's addresses.
            'ipv4_pool' => ['nullable', 'string', Rule::exists('ip_pools', 'uuid')],
            // Chosen IPv4 addresses, the first one primary; missing ones are the next free of the pool.
            'ipv4_addresses' => ['nullable', 'array', 'max:64'],
            'ipv4_addresses.*' => ['ipv4', 'distinct'],
            // Empty: a random password is generated.
            'root_password' => ['nullable', 'string', 'min:12', 'max:128'],
            'ssh_keys' => ['nullable', 'string', 'max:16384', new SshPublicKeys],
            // An app installed once the server has booted (docs/adr/0016 §5); checked by the creator.
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

            if ($package !== null && ! $package->network_models->contains(NetworkModel::from($this->string('network_model')->toString()))) {
                $validator->errors()->add('network_model', __('This package does not allow the selected network model.'));
            }

            if (! $this->filled('hostname') && ($problem = HostnameGenerator::missingHostname($this->string('location')->toString())) !== null) {
                $validator->errors()->add('hostname', $problem);
            }

            if ($this->custom() && $this->integer('ipv4_count') + $this->integer('ipv6_count') === 0) {
                $validator->errors()->add('ipv4_count', __('A server needs at least one IP address.'));
            }

            // docs/adr/0024 §8: customers never get a server of a template not offered to them.
            if ($this->billingSystem() !== null && OsTemplate::query()->where('uuid', $this->string('os_template'))->where('is_active', false)->exists()) {
                $validator->errors()->add('os_template', __(ServerCreator::INACTIVE_TEMPLATE));
            }
        }];
    }

    public function toNewServer(): NewServer
    {
        return new NewServer(
            location: Location::query()->where('uuid', $this->string('location'))->firstOrFail(),
            spec: $this->spec(),
            package: $this->package(),
            osTemplate: OsTemplate::query()->where('uuid', $this->string('os_template'))->firstOrFail(),
            networkModel: NetworkModel::from($this->string('network_model')->toString()),
            hostname: $this->filled('hostname') ? $this->string('hostname')->lower()->toString() : null,
            rootPassword: $this->filled('root_password') ? $this->string('root_password')->toString() : Str::password(20, symbols: false),
            sshKeys: PublicKey::implode(PublicKey::parseMany($this->string('ssh_keys')->toString())),
            externalId: $this->filled('external_id') ? $this->string('external_id')->trim()->toString() : null,
            externalClientId: $this->filled('external_client_id') ? $this->string('external_client_id')->trim()->toString() : null,
            billingSystem: $this->billingSystem(),
            primaryIpv4: $this->ipv4Addresses()[0] ?? null,
            ipv4Pool: $this->filled('ipv4_pool') ? IpPool::query()->where('uuid', $this->string('ipv4_pool'))->firstOrFail() : null,
            additionalIpv4: array_slice($this->ipv4Addresses(), 1),
            app: $this->filled('app') ? AppRecipe::query()->with('latestVersion')->where('slug', $this->string('app'))->firstOrFail() : null,
            appInputs: $this->filled('app') ? (array) $this->input('app_inputs', []) : [],
            placementGroup: $this->custom() && $this->input('placement') === 'group'
                ? PlacementGroup::query()->where('uuid', $this->string('placement_group'))->firstOrFail()
                : null,
            node: $this->node(),
            storage: $this->node() !== null && $this->filled('storage') ? $this->string('storage')->toString() : null,
        );
    }

    /**
     * @return list<string>
     */
    private function ipv4Addresses(): array
    {
        return array_values(array_map(fn ($address) => trim((string) $address), $this->array('ipv4_addresses')));
    }

    private function custom(): bool
    {
        return $this->input('resource_mode') === 'custom';
    }

    /**
     * The node chosen by the administrator, or null for automatic placement.
     */
    private function node(): ?Node
    {
        if (! $this->custom() || $this->input('placement') !== 'node') {
            return null;
        }

        return Node::query()->where('uuid', $this->string('node'))->firstOrFail();
    }

    private function billingSystem(): ?BillingSystem
    {
        return $this->filled('billing_system') ? BillingSystem::query()->where('uuid', $this->string('billing_system'))->first() : null;
    }

    /**
     * The chosen package, or null for a custom server.
     */
    private function package(): ?Package
    {
        return ! $this->custom() && $this->filled('package') ? Package::query()->where('uuid', $this->string('package'))->firstOrFail() : null;
    }

    /**
     * The package's resources, or the ones entered for a custom server.
     */
    private function spec(): ServerSpec
    {
        $package = $this->package();

        if ($package !== null) {
            return ServerSpec::fromPackage($package);
        }

        return new ServerSpec(
            cpuCores: $this->integer('cpu_cores'),
            memoryMib: $this->integer('memory_mib'),
            diskMib: $this->integer('disk_gib') * 1024,
            ipv4Count: $this->integer('ipv4_count'),
            ipv6Count: $this->integer('ipv6_count'),
            portSpeedMbps: $this->filled('port_speed_mbps') ? $this->integer('port_speed_mbps') : null,
            trafficBytes: $this->filled('traffic_gb') ? $this->integer('traffic_gb') * 1000 ** 3 : null,
        );
    }
}
