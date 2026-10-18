<?php

/*
 * Proxmotion server module for WHMCS 8 and 9 (PHP 7.2+).
 *
 * A thin client of the Proxmotion billing API: Proxmotion does the work, this
 * module maps WHMCS services onto it. Setup: README.md next to this file.
 */

use Proxmotion\Whmcs\ApiException;
use Proxmotion\Whmcs\Module;
use Proxmotion\Whmcs\ServerOrder;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

/**
 * @return array<string, mixed>
 */
function proxmotion_MetaData()
{
    return [
        'DisplayName' => 'Proxmotion',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '443',
        'DefaultNonSSLPort' => '80',
        'ServiceSingleSignOnLabel' => false,
        'AdminSingleSignOnLabel' => false,
    ];
}

/**
 * Product module settings: configoption1 to configoption6. The lists are
 * loaded from Proxmotion with the product's server.
 *
 * @return array<string, array<string, mixed>>
 */
function proxmotion_ConfigOptions()
{
    return [
        'Package' => [
            'Type' => 'dropdown',
            'Loader' => 'proxmotion_LoaderPackages',
            'SimpleMode' => true,
            'Description' => 'Resources of the server (configurable options may override them). Without a package, cpu_cores, memory and disk options are required.',
        ],
        'Location' => [
            'Type' => 'dropdown',
            'Loader' => 'proxmotion_LoaderLocations',
            'SimpleMode' => true,
            'Description' => 'Unless the "location" configurable option is chosen',
        ],
        'OS template' => [
            'Type' => 'dropdown',
            'Loader' => 'proxmotion_LoaderOsTemplates',
            'SimpleMode' => true,
            'Description' => 'Unless the "os_template" configurable option is chosen',
        ],
        'Network model' => [
            'Type' => 'dropdown',
            'Options' => [
                'default' => 'First model of the package (bridged without a package)',
                'bridged' => 'Bridged',
                'routed' => 'Routed',
            ],
            'Default' => 'default',
            'SimpleMode' => true,
        ],
        'Restart after upgrades' => [
            'Type' => 'yesno',
            'Description' => 'Restart the server right away when an upgrade or downgrade changes CPU or memory; otherwise they take effect at its next restart',
            'SimpleMode' => true,
        ],
        'Paid app addons' => [
            'Type' => 'textarea',
            'Rows' => 3,
            'Description' => 'Product addons that sell a paid app: one "addon id=app id" per line, e.g. 12=cpanel. Activating the addon lets the server install the app; cancelling it stops new installs.',
            'SimpleMode' => true,
        ],
    ];
}

/**
 * @param array<string, mixed> $params
 * @return array<string, string>
 */
function proxmotion_LoaderPackages(array $params)
{
    // "none": the resources come from configurable options only (see README.md).
    return [ServerOrder::NO_PACKAGE => 'No package (resources from configurable options)']
        + proxmotion_options(Module::fromParams($params)->client()->packages());
}

/**
 * @param array<string, mixed> $params
 * @return array<string, string>
 */
function proxmotion_LoaderLocations(array $params)
{
    return proxmotion_options(Module::fromParams($params)->client()->locations());
}

/**
 * @param array<string, mixed> $params
 * @return array<string, string>
 */
function proxmotion_LoaderOsTemplates(array $params)
{
    return proxmotion_options(Module::fromParams($params)->client()->osTemplates());
}

/**
 * @param list<array<string, mixed>> $items
 * @return array<string, string> id => name
 */
function proxmotion_options(array $items)
{
    $options = [];

    foreach ($items as $item) {
        if (isset($item['id'], $item['name'])) {
            $options[(string) $item['id']] = (string) $item['name'];
        }
    }

    return $options;
}

/**
 * Runs a module command and turns failures into the message WHMCS shows.
 *
 * @param array<string, mixed> $params
 * @param callable(Module): string $command
 */
function proxmotion_run(array $params, callable $command): string
{
    try {
        return $command(Module::fromParams($params));
    } catch (ApiException $e) {
        return $e->describe();
    } catch (Exception $e) {
        logModuleCall('proxmotion', 'error', '', $e->getMessage(), '', []);

        return 'Proxmotion module error: ' . $e->getMessage();
    }
}

/**
 * @param array<string, mixed> $params
 * @return array{success: bool, error: string}
 */
function proxmotion_TestConnection(array $params)
{
    try {
        return Module::fromParams($params)->testConnection();
    } catch (ApiException $e) {
        return ['success' => false, 'error' => $e->describe()];
    }
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_CreateAccount(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->create();
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_SuspendAccount(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->suspend();
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_UnsuspendAccount(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->unsuspend();
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_TerminateAccount(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->terminate();
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_ChangePassword(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->changePassword();
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_ChangePackage(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->changePackage();
    });
}

/**
 * Buttons on the service page of the WHMCS admin area.
 *
 * @return array<string, string>
 */
function proxmotion_AdminCustomButtonArray()
{
    return [
        'Start' => 'Start',
        'Shut down' => 'Shutdown',
        'Reboot' => 'Reboot',
        'Force stop' => 'Stop',
        'Reset root password' => 'ResetPassword',
    ];
}

/**
 * Functions the client area template may call (modop=custom&a=…).
 *
 * @return list<string>
 */
function proxmotion_ClientAreaAllowedFunctions()
{
    return ['Start', 'Shutdown', 'Reboot', 'Stop', 'Console', 'ResetPassword', 'Reinstall', 'SetRdns', 'ChangeHostname', 'InstallApp', 'RetryApp', 'UninstallApp', 'RunAppAction', 'DownloadAppFile'];
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_UninstallApp(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->uninstallApp($_POST);
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_RunAppAction(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->runAppAction($_POST);
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_InstallApp(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->installApp($_POST);
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_RetryApp(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->retryApp($_POST);
    });
}

/**
 * Sends a file output of an installed app (e.g. a VPN profile), fetched from
 * Proxmotion now; WHMCS never stores it.
 *
 * @param array<string, mixed> $params
 */
function proxmotion_DownloadAppFile(array $params): string
{
    $file = null;
    $error = proxmotion_run($params, function (Module $module) use (&$file) {
        $file = $module->appFile(
            isset($_GET['installation']) ? (string) $_GET['installation'] : '',
            isset($_GET['output']) ? (string) $_GET['output'] : '',
            isset($_GET['run']) ? (string) $_GET['run'] : ''
        );

        return 'success';
    });

    if ($error !== 'success' || $file === null) {
        return $error !== 'success' ? $error : 'The file is not available.';
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    header('Content-Length: ' . strlen($file['content']));
    header('Cache-Control: no-store');
    echo $file['content'];
    exit;
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_Start(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->power('start');
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_Shutdown(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->power('shutdown');
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_Reboot(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->power('reboot');
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_Stop(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->power('stop');
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_ResetPassword(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->resetPassword();
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_Reinstall(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->reinstall($_POST);
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_SetRdns(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->setRdns($_POST);
    });
}

/**
 * @param array<string, mixed> $params
 */
function proxmotion_ChangeHostname(array $params): string
{
    return proxmotion_run($params, function (Module $module) {
        return $module->changeHostname($_POST);
    });
}

/**
 * Opens the console: a one-time link from Proxmotion, valid for 60 seconds.
 *
 * @param array<string, mixed> $params
 */
function proxmotion_Console(array $params): string
{
    $url = '';
    $error = proxmotion_run($params, function (Module $module) use (&$url) {
        $url = $module->consoleUrl();

        return 'success';
    });

    if ($error !== 'success' || $url === '') {
        return $error !== 'success' ? $error : 'The console could not be opened.';
    }

    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * The service overview in the client area.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function proxmotion_ClientArea(array $params)
{
    return Module::fromParams($params)->clientArea();
}

/**
 * Server details on the service page of the WHMCS admin area.
 *
 * @param array<string, mixed> $params
 * @return array<string, string>
 */
function proxmotion_AdminServicesTabFields(array $params)
{
    try {
        return Module::fromParams($params)->adminServiceFields();
    } catch (ApiException $e) {
        return ['Proxmotion' => htmlspecialchars($e->describe())];
    }
}
