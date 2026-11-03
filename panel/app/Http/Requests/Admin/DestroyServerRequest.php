<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Server;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting a server from its admin page: the hostname typed again as
 * confirmation, like reinstalls and rebuilds, because the VM, its disks and
 * its addresses are gone for good (AGENTS.md §7.8). Also for servers of a
 * billing system; the billing API terminates without it.
 */
class DestroyServerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->targetServer();

        return [
            'confirm_hostname' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($server) {
                if (! is_string($value) || strcasecmp(trim($value), $server->hostname) !== 0) {
                    $fail(__('Type the hostname of the server to confirm.'));
                }
            }],
        ];
    }

    private function targetServer(): Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : abort(404);
    }
}
