<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Networking\ReverseDns\ForwardDns;
use App\Enums\NetworkModel;
use App\Models\IpAddress;
use App\Models\Server;
use App\Rules\Hostname;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Set the PTR of one of the server's own public addresses (docs/adr/0003).
 * An empty hostname removes the PTR.
 */
class RdnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateRdns', $this->targetServer()) ?? false;
    }

    /**
     * "Mail.Example.com." is fine: case and a trailing dot are normalized first.
     */
    protected function prepareForValidation(): void
    {
        $hostname = $this->input('hostname');

        if (is_string($hostname)) {
            $this->merge(['hostname' => strtolower(rtrim(trim($hostname), '.'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Only the server's own addresses — never another customer's.
            'address' => ['required', 'string', Rule::in($this->targetServer()->ipAddresses()->pluck('address')->all())],
            'hostname' => ['nullable', 'string', 'max:253', 'regex:/\./', new Hostname],
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

            $address = $this->ipAddress();
            $hostname = $this->hostname();

            if ($address->pool->network_model === NetworkModel::Nat) {
                $validator->errors()->add('address', __('Reverse DNS is only available for public addresses.'));
            } elseif ($hostname !== null && $address->pool->rdns_require_forward && ! app(ForwardDns::class)->resolvesTo($hostname, $address->address)) {
                $validator->errors()->add('hostname', __(':hostname must resolve to :address first.', ['hostname' => $hostname, 'address' => $address->address]));
            }
        }];
    }

    public function ipAddress(): IpAddress
    {
        return $this->targetServer()->ipAddresses()->with('pool')->where('address', $this->string('address'))->firstOrFail();
    }

    public function hostname(): ?string
    {
        return $this->filled('hostname') ? $this->string('hostname')->toString() : null;
    }

    private function targetServer(): Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : abort(404);
    }
}
