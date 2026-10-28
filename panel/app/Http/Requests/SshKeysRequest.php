<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Servers\ServerSshKeys;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The whole list of a server's SSH keys as the admin page and the billing
 * API send it (docs/adr/0049); an empty list removes the panel's keys.
 */
class SshKeysRequest extends FormRequest
{
    /** @var list<PublicKey>|null */
    private ?array $parsed = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ssh_keys' => ['present', 'array', 'max:'.ServerSshKeys::MAX_KEYS],
            'ssh_keys.*' => ['string', 'max:16384', new SshPublicKeys],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && count($this->sshKeys()) > ServerSshKeys::MAX_KEYS) {
                $validator->errors()->add('ssh_keys', __('A server can have at most :count SSH keys.', ['count' => ServerSshKeys::MAX_KEYS]));
            }
        }];
    }

    /**
     * The keys, normalized, each once, parsed once. An entry may hold several
     * lines. Validation has checked every line, so a line that still fails
     * here throws: an empty list would remove every key of the server.
     *
     * @return list<PublicKey>
     */
    public function sshKeys(): array
    {
        if ($this->parsed !== null) {
            return $this->parsed;
        }

        $lines = [];

        foreach ((array) $this->input('ssh_keys', []) as $entry) {
            $lines[] = is_string($entry) ? $entry : '';
        }

        return $this->parsed = PublicKey::parseMany(implode("\n", $lines));
    }
}
