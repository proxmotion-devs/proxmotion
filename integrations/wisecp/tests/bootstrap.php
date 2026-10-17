<?php

declare(strict_types=1);

/*
 * Loads the module outside WISECP: stand-ins for the WISECP classes it uses
 * (stubs.php) and the test helpers.
 */

if (! defined('PROXMOTION_WISECP_MODULE')) {
    define('PROXMOTION_WISECP_MODULE', dirname(__DIR__) . '/coremio/modules/Servers/Proxmotion');
}

require_once __DIR__ . '/stubs.php';
require_once PROXMOTION_WISECP_MODULE . '/Proxmotion.php';
require_once __DIR__ . '/FakeTransport.php';
require_once __DIR__ . '/FakeWebhookStore.php';
require_once __DIR__ . '/TestProxmotion.php';
require_once __DIR__ . '/ModuleTestCase.php';
