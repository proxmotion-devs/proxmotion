<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirewallRulesetRequest;
use App\Models\FirewallRuleset;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Firewall rule sets (docs/adr/0035 §3): rules customers apply to their
 * servers and packages start new servers with. Servers keep the rules they
 * got; editing a rule set changes only later applications.
 */
class FirewallRulesetController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/firewall-rulesets/Index', [
            'rulesets' => FirewallRuleset::query()
                ->withCount('packages')
                ->orderBy('name')
                ->get()
                ->map(fn (FirewallRuleset $ruleset) => [
                    'uuid' => $ruleset->uuid,
                    'name' => $ruleset->name,
                    'description' => $ruleset->description,
                    'is_active' => $ruleset->is_active,
                    'rules' => $ruleset->rules,
                    'opens_remote_access' => $ruleset->opensRemoteAccess(),
                    'packages_count' => $ruleset->getAttribute('packages_count'),
                ]),
        ]);
    }

    public function store(FirewallRulesetRequest $request): RedirectResponse
    {
        FirewallRuleset::create($request->rulesetData());

        $this->toast('success', 'Firewall rule set created.');

        return to_route('admin.firewall-rulesets.index');
    }

    public function update(FirewallRulesetRequest $request, FirewallRuleset $firewallRuleset): RedirectResponse
    {
        $firewallRuleset->update($request->rulesetData());

        $this->toast('success', 'Firewall rule set updated.');

        return to_route('admin.firewall-rulesets.index');
    }

    public function destroy(FirewallRuleset $firewallRuleset): RedirectResponse
    {
        $packages = $firewallRuleset->packages()->count();

        if ($packages > 0) {
            $this->toast('error', ':count packages use this rule set. Remove it from them first.', ['count' => (string) $packages]);

            return back();
        }

        $firewallRuleset->delete();

        $this->toast('success', 'Firewall rule set deleted.');

        return to_route('admin.firewall-rulesets.index');
    }
}
