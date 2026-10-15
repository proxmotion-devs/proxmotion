<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\NetworkModel;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\User;
use App\Provisioning\NewServer;
use App\Rules\Hostname;
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
        return [
            'user' => ['required', 'string', Rule::exists('users', 'uuid')->where('role', UserRole::Client->value)],
            'location' => ['required', 'string', Rule::exists('locations', 'uuid')],
            'package' => ['required', 'string', Rule::exists('packages', 'uuid')->where('is_active', true)],
            'os_template' => ['required', 'string', Rule::exists('os_templates', 'uuid')->where('is_active', true)],
            'network_model' => ['required', Rule::enum(NetworkModel::class)->except([NetworkModel::Nat])],
            'hostname' => ['required', 'string', 'max:253', new Hostname],
            // Empty: a random password is generated.
            'root_password' => ['nullable', 'string', 'min:12', 'max:128'],
            'ssh_keys' => ['nullable', 'string', 'max:16384'],
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

            if (! $package->network_models->contains(NetworkModel::from($this->string('network_model')->toString()))) {
                $validator->errors()->add('network_model', __('This package does not allow the selected network model.'));
            }

            foreach (preg_split('/\R/', trim((string) $this->input('ssh_keys', ''))) ?: [] as $key) {
                if ($key !== '' && preg_match('/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-nistp\d+|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com) [A-Za-z0-9+\/=]+( .*)?$/', trim($key)) !== 1) {
                    $validator->errors()->add('ssh_keys', __('Each line must be a public SSH key, e.g. "ssh-ed25519 AAAA… user@host".'));

                    break;
                }
            }
        }];
    }

    public function toNewServer(): NewServer
    {
        return new NewServer(
            owner: User::query()->where('uuid', $this->string('user'))->firstOrFail(),
            location: Location::query()->where('uuid', $this->string('location'))->firstOrFail(),
            package: $this->package(),
            osTemplate: OsTemplate::query()->where('uuid', $this->string('os_template'))->firstOrFail(),
            networkModel: NetworkModel::from($this->string('network_model')->toString()),
            hostname: $this->string('hostname')->lower()->toString(),
            rootPassword: $this->filled('root_password') ? $this->string('root_password')->toString() : Str::password(20, symbols: false),
            sshKeys: $this->filled('ssh_keys') ? trim($this->string('ssh_keys')->toString()) : null,
        );
    }

    private function package(): Package
    {
        return Package::query()->where('uuid', $this->string('package'))->firstOrFail();
    }
}
