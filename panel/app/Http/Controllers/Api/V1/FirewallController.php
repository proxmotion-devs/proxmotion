<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Firewall\ServerFirewalls;
use App\Enums\ServerStatus;
use App\Http\Requests\ApplyRulesetRequest;
use App\Http\Requests\FirewallRulesRequest;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use Illuminate\Http\JsonResponse;

/**
 * A server's firewall (docs/adr/0035): read it, replace its rules, or apply
 * a rule set. Changes answer 202 with the apply_firewall task.
 */
class FirewallController extends ApiController
{
    public function __construct(private readonly ServerFirewalls $firewalls) {}

    public function show(Server $server): JsonResponse
    {
        return response()->json(['data' => $this->firewalls->describe($server)]);
    }

    public function update(FirewallRulesRequest $request, Server $server): JsonResponse
    {
        if (! self::changeable($server)) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($this->firewalls->change($server, $request->enabled(), $request->firewallRules(), $this->token($request), $request->ip()));
        } catch (TaskAlreadyRunning $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }
    }

    public function ruleset(ApplyRulesetRequest $request, Server $server): JsonResponse
    {
        if (! self::changeable($server)) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($this->firewalls->applyRuleset($server, $request->ruleset(), $request->mode(), $this->token($request), $request->ip()));
        } catch (TaskAlreadyRunning $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }
    }

    private static function changeable(Server $server): bool
    {
        return in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true);
    }
}
