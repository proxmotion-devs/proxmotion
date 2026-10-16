<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Networking\ReverseDns\ReverseDnsDrivers;
use App\Domain\Networking\ReverseDns\ReverseDnsFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DnsProviderRequest;
use App\Models\DnsProvider;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where reverse DNS is published (docs/adr/0003). API keys are write-only.
 */
class DnsProviderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/dns-providers/Index', [
            'providers' => DnsProvider::query()->withCount('ipPools')->orderBy('name')->get()->map(fn (DnsProvider $provider) => [
                'uuid' => $provider->uuid,
                'name' => $provider->name,
                'driver' => $provider->driver,
                'api_url' => $provider->options['api_url'] ?? null,
                'server_id' => $provider->options['server_id'] ?? null,
                'has_api_key' => ($provider->credentials['api_key'] ?? '') !== '',
                'ip_pools_count' => $provider->ip_pools_count,
            ]),
        ]);
    }

    public function store(DnsProviderRequest $request): RedirectResponse
    {
        $request->fillProvider(new DnsProvider)->save();
        $this->toast('success', 'DNS provider created.');

        return to_route('admin.dns-providers.index');
    }

    public function update(DnsProviderRequest $request, DnsProvider $dnsProvider): RedirectResponse
    {
        $request->fillProvider($dnsProvider)->save();
        $this->toast('success', 'DNS provider updated.');

        return back();
    }

    public function destroy(DnsProvider $dnsProvider): RedirectResponse
    {
        if ($dnsProvider->ipPools()->exists()) {
            $this->toast('error', 'IP pools still use this DNS provider. Change them first.');

            return back();
        }

        $dnsProvider->delete();
        $this->toast('success', 'DNS provider deleted.');

        return back();
    }

    public function test(DnsProvider $dnsProvider, ReverseDnsDrivers $drivers): RedirectResponse
    {
        try {
            $drivers->for($dnsProvider)->test();
        } catch (ReverseDnsFailed $e) {
            $this->toast('error', 'Connection failed: :message', ['message' => $e->getMessage()]);

            return back();
        }

        $this->toast('success', 'Connection works.');

        return back();
    }
}
