<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Networking\AddressInput;
use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpPool;
use App\Models\Location;
use App\Support\Network\Cidr;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Network, version and location are fixed after creation: changing them would
 * invalidate the pool's addresses.
 */
class IpPoolRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = ! $this->pool() instanceof IpPool;

        return [
            // Only read when creating; ignored on update.
            'location' => [$creating ? 'required' : 'nullable', 'string', Rule::exists('locations', 'uuid')],
            'version' => [$creating ? 'required' : 'nullable', 'integer', Rule::enum(IpVersion::class)],
            'network' => [$creating ? 'required' : 'nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:100'],
            'network_model' => ['required', Rule::enum(NetworkModel::class)],
            'gateway' => ['nullable', 'ip'],
            'bridge' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,15}$/'],
            'vlan_tag' => ['nullable', 'integer', 'between:1,4094'],
            'nameservers' => ['array', 'max:4'],
            'nameservers.*' => ['required', 'ip'],
            'is_active' => ['required', 'boolean'],
            // Creating only: which addresses to add right away.
            'add_mode' => [$creating ? 'required' : 'nullable', Rule::in(self::ADD_MODES)],
            'addresses' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /**
     * list: only the addresses typed in; network: every usable address of the
     * network (only when the whole block is yours); none: add them later.
     */
    public const array ADD_MODES = ['list', 'network', 'none'];

    private ?AddressInput $parsedAddresses = null;

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $pool = $this->pool();
            $version = $pool?->version->value ?? $this->integer('version');
            $networkValue = $pool->network ?? $this->string('network')->toString();

            if (! Cidr::isValid($networkValue)) {
                $validator->errors()->add('network', __('Enter the network in CIDR notation, e.g. 203.0.113.0/24.'));

                return;
            }

            $network = Cidr::parse($networkValue);

            if ($network->version !== $version) {
                $validator->errors()->add('network', __('The network does not match the selected IP version.'));
            }

            $gateway = $this->input('gateway');

            if (is_string($gateway) && $gateway !== '') {
                $gatewayVersion = filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;

                if ($gatewayVersion !== $version) {
                    $validator->errors()->add('gateway', __('The gateway does not match the selected IP version.'));
                } elseif ($this->input('network_model') === NetworkModel::Bridged->value && ! $network->contains($gateway)) {
                    // Routed (failover) IPs use the host's gateway, which is outside the routed block.
                    $validator->errors()->add('gateway', __('For bridged networks the gateway must be inside the network.'));
                }
            }

            if ($pool !== null || $network->version !== $version) {
                return;
            }

            if ($this->input('add_mode') === 'list') {
                $this->parsedAddresses = AddressInput::parse((string) $this->input('addresses', ''), IpVersion::from($version), $network);

                foreach ($this->parsedAddresses->problems() as $problem) {
                    $validator->errors()->add('addresses', $problem);
                }
            }

            if ($this->input('add_mode') === 'network' && $version !== IpVersion::V4->value) {
                $validator->errors()->add('add_mode', __('Whole networks can only be added for IPv4 pools.'));
            }
        }];
    }

    public function addMode(): string
    {
        return $this->string('add_mode')->toString();
    }

    /**
     * @return list<string>
     */
    public function addressesToAdd(): array
    {
        return $this->parsedAddresses->addresses ?? [];
    }

    public function pool(): ?IpPool
    {
        $pool = $this->route('ipPool');

        return $pool instanceof IpPool ? $pool : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function poolData(): array
    {
        $data = $this->safe()->except(['location', 'version', 'network', 'add_mode', 'addresses']);
        $data['nameservers'] = array_values($this->array('nameservers'));
        $data['gateway'] = $this->filled('gateway') ? $this->string('gateway')->toString() : null;

        if ($this->pool() === null) {
            $data['location_id'] = Location::query()->where('uuid', $this->string('location'))->value('id');
            $data['version'] = $this->integer('version');
            $data['network'] = (string) Cidr::parse($this->string('network')->toString());
        }

        return $data;
    }
}
