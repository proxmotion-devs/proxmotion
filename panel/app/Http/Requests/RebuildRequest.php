<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Server;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rebuild from scratch (docs/adr/0032 §7): the hostname typed again as
 * confirmation, because the server's VM and its disks are deleted.
 */
class RebuildRequest extends FormRequest
{
    /**
     * Checked before validation.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('rebuild', $this->targetServer()) ?? false;
    }

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
