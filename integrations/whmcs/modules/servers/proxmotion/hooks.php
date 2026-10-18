<?php

/*
 * WHMCS hooks of the Proxmotion module, loaded by WHMCS from the module's
 * directory: paid apps sold as product addons (docs/adr/0019, README.md).
 */

use Proxmotion\Whmcs\AddonHooks;
use Proxmotion\Whmcs\Module;
use Proxmotion\Whmcs\WhmcsServiceStore;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

/**
 * @return AddonHooks
 */
function proxmotion_addon_hooks()
{
    return new AddonHooks(new WhmcsServiceStore(), function (array $params) {
        return Module::fromParams($params);
    });
}

add_hook('AddonActivation', 1, function (array $vars) {
    proxmotion_addon_hooks()->activated($vars);
});

add_hook('AddonCancelled', 1, function (array $vars) {
    proxmotion_addon_hooks()->ended($vars);
});

add_hook('AddonTerminated', 1, function (array $vars) {
    proxmotion_addon_hooks()->ended($vars);
});
