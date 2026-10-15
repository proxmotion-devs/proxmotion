<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Networking\AddressInput;
use App\Models\IpPool;
use App\Support\Network\Cidr;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * One text field that accepts single IPs, lists, IPv4 ranges (a-b) and IPv4 blocks (a/29).
 */
class IpAddressStoreRequest extends FormRequest
{
    private ?AddressInput $parsed = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'addresses' => ['required', 'string', 'max:65535'],
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

            $pool = $this->pool();
            $this->parsed = AddressInput::parse($this->string('addresses')->toString(), $pool->version, Cidr::parse($pool->network));

            foreach ($this->parsed->problems() as $problem) {
                $validator->errors()->add('addresses', $problem);
            }
        }];
    }

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return $this->parsed->addresses ?? [];
    }

    public function pool(): IpPool
    {
        $pool = $this->route('ipPool');

        abort_unless($pool instanceof IpPool, 404);

        return $pool;
    }
}
