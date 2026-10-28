<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Servers\ServerReinstaller;
use App\Domain\Servers\ServerSshKeys;
use App\Models\AppRecipe;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/servers/{id}/reinstall: an OS template the server can use
 * and optionally public SSH keys (else it keeps its own), optionally an app installed on the new
 * system (docs/adr/0016 §5). The billing module asks the customer to
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
            // Left out: the server keeps its keys; an empty list removes them (docs/adr/0049 §9).
            'ssh_keys' => ['nullable', 'array', 'max:'.ServerSshKeys::MAX_KEYS],
            'ssh_keys.*' => ['string', 'max:16384', new SshPublicKeys],
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
     * The keys of the new system: those sent, or without ssh_keys the
     * server's current keys (docs/adr/0049 §9); an empty list clears them.
     *
     * @return list<PublicKey>
     */
    public function sshKeys(): array
    {
        $server = $this->route('server');

        if ($this->input('ssh_keys') === null && $server instanceof Server) {
            return ServerSshKeys::of($server);
        }

        $keys = [];

        foreach ((array) $this->input('ssh_keys', []) as $line) {
            $keys = [...$keys, ...PublicKey::parseMany((string) $line)];
        }

        return $keys;
    }
}
