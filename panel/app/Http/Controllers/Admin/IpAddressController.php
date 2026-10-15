<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Networking\GenerateIpAddresses;
use App\Enums\IpVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IpAddressStoreRequest;
use App\Http\Requests\Admin\IpAddressUpdateRequest;
use App\Models\IpAddress;
use App\Models\IpPool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class IpAddressController extends Controller
{
    public function store(IpAddressStoreRequest $request, IpPool $ipPool, GenerateIpAddresses $generate): RedirectResponse
    {
        if ($ipPool->version === IpVersion::V6) {
            $ipPool->addresses()->create(['address' => inet_ntop((string) inet_pton($request->string('address')->toString()))]);

            $this->toast('success', 'Address added.');

            return back();
        }

        try {
            $added = $generate->handle($ipPool, $request->string('from')->toString(), $request->string('to')->toString());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'to' => __('Add at most :max addresses at once.', ['max' => (string) GenerateIpAddresses::MAX_ADDRESSES]),
            ]);
        }

        $this->toast('success', ':count addresses added.', ['count' => (string) $added]);

        return back();
    }

    public function update(IpAddressUpdateRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $ipAddress->update($request->addressData());

        return back();
    }

    public function destroy(IpAddress $ipAddress): RedirectResponse
    {
        // From Phase 2, assigned addresses must not be deletable.
        $ipAddress->delete();

        $this->toast('success', 'Address deleted.');

        return back();
    }
}
