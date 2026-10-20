<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\IpVersion;
use App\Models\IpPool;
use App\Provisioning\AddressAddition;
use App\Provisioning\AddressChange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Editing a server's addresses on the admin server page (docs/adr/0028):
 * "add" rows (IP version, optional pool, optional specific address), "remove"
 * (addresses of the server) and the new primary per IP version.
 */
class AddressChangeRequest extends FormRequest
{
    /** Most addresses one change may add or remove. */
    public const MAX_ROWS = 32;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'add' => ['nullable', 'array', 'max:'.self::MAX_ROWS],
            'add.*.ip_version' => ['required', 'integer', 'in:4,6'],
            'add.*.pool' => ['nullable', 'string', 'exists:ip_pools,uuid'],
            'add.*.address' => ['nullable', 'string', 'ip'],
            'remove' => ['nullable', 'array', 'max:'.self::MAX_ROWS],
            'remove.*' => ['required', 'string', 'ip', 'distinct'],
            'primary_ipv4' => ['nullable', 'string', 'ipv4'],
            'primary_ipv6' => ['nullable', 'string', 'ipv6'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('add', []) as $index => $row) {
                    $address = is_array($row) ? ($row['address'] ?? null) : null;
                    $version = is_array($row) ? (int) ($row['ip_version'] ?? 0) : 0;

                    if (is_string($address) && $address !== '' && $version !== 0 && self::versionOf($address) !== $version) {
                        $validator->errors()->add("add.{$index}.address", __('This is not an IPv:version address.', ['version' => (string) $version]));
                    }
                }
            },
        ];
    }

    public function toChange(): AddressChange
    {
        $pools = IpPool::query()->whereIn('uuid', array_filter(array_map(
            fn ($row) => is_array($row) ? ($row['pool'] ?? null) : null,
            (array) $this->validated('add', []),
        )))->get()->keyBy('uuid');

        $add = [];

        foreach ((array) $this->validated('add', []) as $row) {
            $pool = $row['pool'] ?? null;
            $address = trim((string) ($row['address'] ?? ''));

            $add[] = new AddressAddition(
                IpVersion::from((int) $row['ip_version']),
                $pool === null ? null : $pools->get($pool),
                $address === '' ? null : $address,
            );
        }

        $primary = array_filter([
            IpVersion::V4->value => $this->filled('primary_ipv4') ? $this->string('primary_ipv4')->trim()->value() : null,
            IpVersion::V6->value => $this->filled('primary_ipv6') ? $this->string('primary_ipv6')->trim()->value() : null,
        ], fn (?string $address) => $address !== null);

        return new AddressChange(
            add: $add,
            remove: array_values(array_map(fn ($address) => trim((string) $address), (array) $this->validated('remove', []))),
            primary: $primary,
        );
    }

    private static function versionOf(string $address): int
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : 4;
    }
}
