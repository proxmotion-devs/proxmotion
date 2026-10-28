<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Servers\ServerReinstaller;
use App\Models\AppRecipe;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Rules\SshKeyLimit;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reinstall: an OS template the server can use, optional public SSH keys
 * (one per line), and the hostname typed again as confirmation (the disks
 * are wiped).
 */
class ReinstallRequest extends FormRequest
{
    /**
     * Checked before validation.
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
            'ssh_keys' => ['nullable', 'string', 'max:16384', new SshPublicKeys, new SshKeyLimit],
            'confirm_hostname' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($server) {
                if (! is_string($value) || strcasecmp(trim($value), $server->hostname) !== 0) {
                    $fail(__('Type the hostname of the server to confirm.'));
                }
            }],
            // An app installed once the new system has booted (docs/adr/0016 §5); checked by the reinstaller.
            'app' => ['nullable', 'string', Rule::exists('app_recipes', 'slug')],
            'app_inputs' => ['nullable', 'array'],
        ];
    }

    public function app(): ?AppRecipe
    {
        return $this->filled('app') ? AppRecipe::query()->with('latestVersion')->where('slug', $this->string('app'))->firstOrFail() : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function appInputs(): array
    {
        return (array) $this->input('app_inputs', []);
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
        return PublicKey::parseMany($this->string('ssh_keys')->toString());
    }

    private function targetServer(): Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : abort(404);
    }
}
