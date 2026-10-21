<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Firewall\ServerFirewalls;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyRulesetRequest;
use App\Http\Requests\FirewallRulesRequest;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * A server's firewall on its admin page (docs/adr/0035): the same changes
 * customers make through the billing API.
 */
class ServerFirewallController extends Controller
{
    public function __construct(private readonly ServerFirewalls $firewalls) {}

    public function update(FirewallRulesRequest $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            $this->firewalls->change($server, $request->policy(), $request->firewallRules(), $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        }

        $this->toast('info', 'Applying the firewall…');

        return back();
    }

    public function ruleset(ApplyRulesetRequest $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            $this->firewalls->applyRuleset($server, $request->ruleset(), $request->mode(), $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        }

        $this->toast('info', 'Applying the firewall…');

        return back();
    }
}
