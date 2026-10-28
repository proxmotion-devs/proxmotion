<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Servers\ServerSshKeys;
use App\Rules\SshPublicKeys;
use App\Support\Ssh\PublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The whole list of a server's SSH keys as the admin page and the billing
 * API send it (docs/adr/0049); an empty list removes the panel's keys.
 */
class SshKeysRequest extends FormRequest
{
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
     * The keys, normalized, each once. An entry may hold several lines.
     *
     * @return list<PublicKey>
     */
    public function sshKeys(): array
    {
        $lines = [];

        foreach ((array) $this->input('ssh_keys', []) as $entry) {
            $lines[] = is_string($entry) ? $entry : '';
        }

        try {
            return PublicKey::parseMany(implode("\n", $lines));
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}
