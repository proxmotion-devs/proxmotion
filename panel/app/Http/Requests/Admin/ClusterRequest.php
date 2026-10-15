<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cluster;
use App\Models\Location;
use App\Rules\Host;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClusterRequest extends FormRequest
{
    /**
     * Proxmox API token IDs look like "user@realm!tokenname".
     */
    private const TOKEN_ID = '/^[^\s@!]+@[^\s@!]+![A-Za-z][A-Za-z0-9._-]*$/';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = ! $this->route('cluster') instanceof Cluster;

        return [
            'location' => ['required', 'string', Rule::exists('locations', 'uuid')],
            'name' => ['required', 'string', 'max:100'],
            'hosts' => ['required', 'array', 'min:1', 'max:8'],
            'hosts.*' => ['required', 'string', 'distinct', new Host],
            'port' => ['required', 'integer', 'between:1,65535'],
            'token_id' => ['required', 'string', 'max:128', 'regex:'.self::TOKEN_ID],
            // Leave empty when editing to keep the stored secret.
            'token_secret' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'verify_tls' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clusterData(): array
    {
        $data = $this->safe()->except(['location', 'token_secret']);
        $data['location_id'] = Location::query()->where('uuid', $this->string('location'))->value('id');
        $data['hosts'] = array_values($this->array('hosts'));

        if ($this->filled('token_secret')) {
            $data['token_secret'] = $this->string('token_secret')->toString();
        }

        return $data;
    }
}
