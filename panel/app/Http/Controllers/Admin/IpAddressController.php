<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Networking\AddIpAddresses;
use App\Domain\Networking\AddressInput;
use App\Http\Controllers\Admin\Concerns\ReportsAddedAddresses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IpAddressStoreRequest;
use App\Http\Requests\Admin\IpAddressUpdateRequest;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Support\Network\Cidr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IpAddressController extends Controller
{
    use ReportsAddedAddresses;

    public function store(IpAddressStoreRequest $request, IpPool $ipPool, AddIpAddresses $add): RedirectResponse
    {
        $this->reportAddedAddresses($add->handle($ipPool, $request->addresses()));

        return back();
    }

    public function update(IpAddressUpdateRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $ipAddress->update($request->addressData());

        return back();
    }

    public function destroy(IpAddress $ipAddress): RedirectResponse
    {
        if ($ipAddress->server_id !== null || $ipAddress->server_move_id !== null) {
            $this->toast('error', 'This address is assigned to a server.');

            return back();
        }

        $ipAddress->delete();

        $this->toast('success', 'Address deleted.');

        return back();
    }

    /**
     * Delete, reserve or unreserve selected addresses of a pool. Addresses
     * assigned to servers are never deleted.
     */
    public function bulk(Request $request, IpPool $ipPool): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['delete', 'reserve', 'unreserve'])],
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
        ]);

        $query = $ipPool->addresses()->whereIn('id', $validated['ids']);

        $action = $request->string('action')->toString();

        $count = match ($action) {
            'delete' => (clone $query)->whereNull('server_id')->whereNull('server_move_id')->delete(),
            'reserve' => (clone $query)->update(['is_reserved' => true]),
            default => (clone $query)->update(['is_reserved' => false]),
        };

        $skipped = $action === 'delete' ? (clone $query)->count() : 0;

        $this->toast(
            'success',
            $skipped > 0 ? ':count addresses updated. :skipped are assigned to servers and were kept.' : ':count addresses updated.',
            ['count' => (string) $count, 'skipped' => (string) $skipped],
        );

        return back();
    }

    /**
     * "Keep only these": delete every unassigned address of the pool that is
     * not in the given list. Assigned addresses are always kept.
     */
    public function keepOnly(Request $request, IpPool $ipPool): RedirectResponse
    {
        $request->validate(['addresses' => ['required', 'string', 'max:65535']]);

        $input = AddressInput::parse($request->string('addresses')->toString(), $ipPool->version, Cidr::parse($ipPool->network));

        if ($input->problems() !== []) {
            throw ValidationException::withMessages(['addresses' => $input->problems()]);
        }

        $deleted = $ipPool->addresses()
            ->whereNull('server_id')
            ->whereNull('server_move_id')
            ->whereNotIn('address', $input->addresses)
            ->delete();

        $this->toast('success', ':count addresses deleted.', ['count' => (string) $deleted]);

        return back();
    }
}
