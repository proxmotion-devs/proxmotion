<?php

/*
 * Proxmotion's webhooks arrive at POST /api/v1/proxmotion/webhook: a public
 * route of WISECP's API (no API key; the receiver checks the signature) that
 * WISECP hands to Proxmotion::api_webhook().
 */
defined('CORE_FOLDER') or exit('You can not get in here!');

Hook::add('filter:api.routes', 10, function (&$routes, &$audience) {
    if ($audience !== 'module') {
        return;
    }

    $routes[] = ['POST', 'proxmotion/webhook', 'Module:Servers/Proxmotion', 'webhook', true];
});
