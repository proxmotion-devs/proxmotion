<?php

/*
 * Loads the module outside WHMCS: the WHMCS constant and the WHMCS functions
 * the module calls, which record their calls in $GLOBALS['whmcs_calls'].
 */

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

$GLOBALS['whmcs_calls'] = ['logModuleCall' => [], 'localAPI' => []];

if (!function_exists('logModuleCall')) {
    /**
     * @param mixed $request
     * @param mixed $response
     * @param mixed $processed
     * @param list<string> $replace
     */
    function logModuleCall($module, $action, $request, $response, $processed = '', $replace = [])
    {
        // Like WHMCS: the values in $replace are masked before the entry is stored.
        $mask = function ($value) use ($replace) {
            return is_string($value) ? str_replace(array_filter($replace, 'strlen'), '***', $value) : $value;
        };

        $GLOBALS['whmcs_calls']['logModuleCall'][] = [
            'module' => $module,
            'action' => $action,
            'request' => $mask($request),
            'response' => $mask($response),
        ];
    }
}

if (!function_exists('localAPI')) {
    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    function localAPI($command, $values = [], $adminUser = '')
    {
        $GLOBALS['whmcs_calls']['localAPI'][] = ['command' => $command, 'values' => $values];

        return ['result' => 'success'];
    }
}

require_once __DIR__ . '/../modules/servers/proxmotion/proxmotion.php';
require_once __DIR__ . '/FakeTransport.php';
require_once __DIR__ . '/ModuleTestCase.php';
