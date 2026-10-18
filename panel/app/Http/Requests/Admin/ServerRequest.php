<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\NetworkModel;
use App\Models\AppRecipe;
use App\Models\BillingSystem;
use App\Models\Location;
use App\Models\Node;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\PlacementGroup;
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

class ServerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $custom = $this->input('resource_mode') === 'custom';

        return [
            // The billing system that owns the server, and the ids of the service and customer there.
            'billing_system' => ['nullable', 'required_with:external_id,external_client_id', 'string', Rule::exists('billing_systems', 'uuid')],
            'external_id' => ['nullable', 'string', 'max:191', Rule::unique('servers', 'live_external_id')->where('billing_system_id', $this->billingSystem()?->id)],
            'external_client_id' => ['nullable', 'string', 'max:191'],
            'location' => ['required', 'string', Rule::exists('locations', 'uuid')],
            // "package": resources from a package; "custom": entered below (admins only).
            'resource_mode' => ['required', Rule::in(['package', 'custom'])],
            'package' => [Rule::requiredIf(! $custom), 'nullable', 'string', Rule::exists('packages', 'uuid')->where('is_active', true)],
            // Same limits as the package form.
            'cpu_cores' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:1,512'],
            'memory_mib' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:128,4194304'],
            'disk_gib' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:1,65536'],
            'ipv4_count' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:0,64'],
            'ipv6_count' => [Rule::requiredIf($custom), 'nullable', 'integer', 'between:0,64'],
            'port_speed_mbps' => ['nullable', 'integer', 'between:1,400000'],
            'traffic_gb' => ['nullable', 'integer', 'between:1,10000000'],
            // "group": the best node of a placement group (docs/adr/0020); "node": the node
            // (and optionally storage) chosen here. The billing API still places automatically.
            'placement' => ['required', Rule::in(['group', 'node'])],
            'placement_group' => ['nullable', 'required_if:placement,group', 'string', Rule::exists('placement_groups', 'uuid')],
            'node' => ['nullable', 'required_if:placement,node', 'string', Rule::exists('nodes', 'uuid')],
            // Empty: the storage of the node with the most room.
            'storage' => ['nullable', 'string', 'max:191'],
            'os_template' => ['required', 'string', Rule::exists('os_templates', 'uuid')->where('is_active', true)],
            'network_model' => ['required', Rule::enum(NetworkModel::class)->except([NetworkModel::Nat])],
            'hostname' => ['required', 'string', 'max:253', new Hostname],
            // Empty: the next free address of the location's pools.
            'primary_ipv4' => ['nullable', 'ipv4'],
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

            if ($package === null && $this->integer('ipv4_count') + $this->integer('ipv6_count') === 0) {
                $validator->errors()->add('ipv4_count', __('A server needs at least one IP address.'));
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
            hostname: $this->string('hostname')->lower()->toString(),
            rootPassword: $this->filled('root_password') ? $this->string('root_password')->toString() : Str::password(20, symbols: false),
            sshKeys: PublicKey::implode(PublicKey::parseMany($this->string('ssh_keys')->toString())),
            externalId: $this->filled('external_id') ? $this->string('external_id')->trim()->toString() : null,
            externalClientId: $this->filled('external_client_id') ? $this->string('external_client_id')->trim()->toString() : null,
            billingSystem: $this->billingSystem(),
            primaryIpv4: $this->filled('primary_ipv4') ? $this->string('primary_ipv4')->trim()->toString() : null,
            app: $this->filled('app') ? AppRecipe::query()->with('latestVersion')->where('slug', $this->string('app'))->firstOrFail() : null,
            appInputs: $this->filled('app') ? (array) $this->input('app_inputs', []) : [],
            placementGroup: $this->input('placement') === 'group'
                ? PlacementGroup::query()->where('uuid', $this->string('placement_group'))->firstOrFail()
                : null,
            node: $this->node(),
            storage: $this->node() !== null && $this->filled('storage') ? $this->string('storage')->toString() : null,
        );
    }

    /**
     * The node chosen by the administrator, or null for automatic placement.
     */
    private function node(): ?Node
    {
        if ($this->input('placement') !== 'node') {
            return null;
        }

        return Node::query()->where('uuid', $this->string('node'))->firstOrFail();
    }

    private function billingSystem(): ?BillingSystem
    {
        return $this->filled('billing_system') ? BillingSystem::query()->where('uuid', $this->string('billing_system'))->first() : null;
    }

    /**
     * The chosen package, or null for custom resources.
     */
    private function package(): ?Package
    {
        if ($this->input('resource_mode') === 'custom') {
            return null;
        }

        return Package::query()->where('uuid', $this->string('package'))->firstOrFail();
    }

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
