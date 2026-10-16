<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Servers\ServerReinstaller;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\SshKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by the admin and the client reinstall: an OS template the server can
 * use, keys that belong to the server's owner, and the hostname typed again
 * as confirmation (the disks are wiped).
 */
class ReinstallRequest extends FormRequest
{
    /**
     * Checked before validation, so others learn nothing about the server.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reinstall', $this->targetServer()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->targetServer();

        return [
            'os_template' => ['required', 'string', Rule::in(app(ServerReinstaller::class)->eligibleTemplates($server)->pluck('uuid')->all())],
            'ssh_keys' => ['array', 'max:50'],
            // Only keys of the server's owner — never another user's.
            'ssh_keys.*' => ['string', 'distinct', Rule::in($server->user->sshKeys()->pluck('uuid')->all())],
            'confirm_hostname' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($server) {
                if (! is_string($value) || strcasecmp(trim($value), $server->hostname) !== 0) {
                    $fail(__('Type the hostname of the server to confirm.'));
                }
            }],
        ];
    }

    public function osTemplate(): OsTemplate
    {
        return OsTemplate::query()->where('uuid', $this->string('os_template'))->firstOrFail();
    }

    /**
     * @return Collection<int, SshKey>
     */
    public function sshKeys(): Collection
    {
        return $this->targetServer()->user->sshKeys()->whereIn('uuid', $this->array('ssh_keys'))->orderBy('id')->get();
    }

    private function targetServer(): Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : abort(404);
    }
}
