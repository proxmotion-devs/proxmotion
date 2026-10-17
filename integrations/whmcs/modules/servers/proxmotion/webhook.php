<?php

/*
 * Receiver of Proxmotion's webhooks: https://<your WHMCS>/modules/servers/proxmotion/webhook.php
 * Enter that URL in Proxmotion (Admin → Billing systems → Add webhook) and the
 * signing secret (whsec_…) as the Access Hash of the WHMCS server. README.md.
 */

use Proxmotion\Whmcs\Module;
use Proxmotion\Whmcs\WebhookReceiver;
use Proxmotion\Whmcs\WhmcsWebhookStore;

require __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/autoload.php';

$headers = [];

foreach ($_SERVER as $name => $value) {
    if (strncmp($name, 'HTTP_', 5) === 0) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
    }
}

$receiver = new WebhookReceiver(new WhmcsWebhookStore(), function (array $params) {
    return Module::fromParams($params)->client();
});
$response = $receiver->handle(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET', $headers, (string) file_get_contents('php://input'));

http_response_code($response['status']);
header('Content-Type: text/plain; charset=utf-8');
echo $response['body'];
