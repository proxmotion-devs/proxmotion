<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\IpVersion;
use App\Models\IpPool;
use App\Support\Network\Cidr;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * IPv4 pools take a range (from–to), IPv6 pools a single address.
 */
class IpAddressStoreRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->pool()->version === IpVersion::V4) {
            return [
                'from' => ['required', 'ipv4'],
                'to' => ['required', 'ipv4'],
            ];
        }

        return [
            'address' => ['required', 'ipv6', 'unique:ip_addresses,address'],
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

            $network = Cidr::parse($this->pool()->network);
            $fields = $this->pool()->version === IpVersion::V4 ? ['from', 'to'] : ['address'];

            foreach ($fields as $field) {
                if (! $network->contains($this->string($field)->toString())) {
                    $validator->errors()->add($field, __('The address must be inside :network.', ['network' => (string) $network]));
                }
            }

            if ($fields === ['from', 'to'] && ip2long($this->string('from')->toString()) > ip2long($this->string('to')->toString())) {
                $validator->errors()->add('to', __('The end of the range must not be before its start.'));
            }
        }];
    }

    public function pool(): IpPool
    {
        $pool = $this->route('ipPool');

        abort_unless($pool instanceof IpPool, 404);

        return $pool;
    }
}
