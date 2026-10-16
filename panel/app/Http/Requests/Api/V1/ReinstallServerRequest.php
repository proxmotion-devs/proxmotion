<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Servers\ServerReinstaller;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/servers/{id}/reinstall: an OS template the server can use
 * and optional public SSH keys. The billing module asks the customer to
 * confirm; the panel's hostname confirmation is for its own admin form.
 */
class ReinstallServerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->route('server');
        $eligible = $server instanceof Server ? app(ServerReinstaller::class)->eligibleTemplates($server)->pluck('uuid')->all() : [];

        return [
            'os_template' => ['required', 'string', Rule::in($eligible)],
            'ssh_keys' => ['nullable', 'array', 'max:20'],
            'ssh_keys.*' => ['string', 'max:16384', new SshPublicKeys],
        ];
    }

    public function osTemplate(): OsTemplate
    {
        return OsTemplate::query()->where('uuid', $this->string('os_template'))->firstOrFail();
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
}
