<?php

namespace Proxmotion\Whmcs;

use Exception;

/**
 * Paid apps sold as product addons (docs/adr/0019): activating an addon that
 * module setting 6 maps to an app grants the server the right to install it;
 * cancelling or terminating the addon revokes it (an installed copy stays).
 * Called from hooks.php; failures are written to the activity log and never
 * stop WHMCS.
 */
class AddonHooks
{
    /** @var ServiceStore */
    private $store;

    /** @var callable(array<string, mixed>): Module */
    private $modules;

    /**
     * @param callable(array<string, mixed>): Module $modules makes the module of a service
     */
    public function __construct(ServiceStore $store, callable $modules)
    {
        $this->store = $store;
        $this->modules = $modules;
    }

    /**
     * AddonActivation: id (the service's addon), userid, serviceid, addonid.
     *
     * @param array<string, mixed> $vars
     */
    public function activated(array $vars): void
    {
        $this->handle($vars, true);
    }

    /**
     * AddonCancelled and AddonTerminated, with the same variables.
     *
     * @param array<string, mixed> $vars
     */
    public function ended(array $vars): void
    {
        $this->handle($vars, false);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function handle(array $vars, bool $grant): void
    {
        $serviceId = isset($vars['serviceid']) ? (int) $vars['serviceid'] : 0;
        $addonId = isset($vars['addonid']) ? (int) $vars['addonid'] : 0;
        $userId = isset($vars['userid']) ? (int) $vars['userid'] : 0;

        if ($serviceId <= 0 || $addonId <= 0) {
            return;
        }

        $params = $this->store->moduleParams($serviceId);

        if ($params === null) {
            return;
        }

        try {
            $module = call_user_func($this->modules, $params);
            $app = $grant
                ? $module->grantAddon($addonId, isset($vars['id']) ? (int) $vars['id'] : 0)
                : $module->revokeAddon($addonId);
        } catch (ApiException $e) {
            $this->store->log(sprintf('Proxmotion: could not %s the paid app of addon #%d for service #%d: %s', $grant ? 'grant' : 'revoke', $addonId, $serviceId, $e->describe()), $userId);

            return;
        } catch (Exception $e) {
            $this->store->log(sprintf('Proxmotion: could not %s the paid app of addon #%d for service #%d: %s', $grant ? 'grant' : 'revoke', $addonId, $serviceId, $e->getMessage()), $userId);

            return;
        }

        if ($app !== null) {
            $this->store->log(sprintf('Proxmotion: %s the paid app %s for service #%d (addon #%d)', $grant ? 'granted' : 'revoked', $app, $serviceId, $addonId), $userId);
        }
    }
}
