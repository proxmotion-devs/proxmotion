<?php

use App\Http\Controllers\Admin\AdoptionController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AppController;
use App\Http\Controllers\Admin\AppEntitlementController;
use App\Http\Controllers\Admin\AppLogController;
use App\Http\Controllers\Admin\AppRecipeController;
use App\Http\Controllers\Admin\BillingSystemController;
use App\Http\Controllers\Admin\ClusterController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DnsProviderController;
use App\Http\Controllers\Admin\DriftController;
use App\Http\Controllers\Admin\FirewallRulesetController;
use App\Http\Controllers\Admin\GuestScriptController;
use App\Http\Controllers\Admin\HelpController;
use App\Http\Controllers\Admin\IpAddressController;
use App\Http\Controllers\Admin\IpPoolController;
use App\Http\Controllers\Admin\IpPoolRdnsController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\NodeController;
use App\Http\Controllers\Admin\OperatingSystemController;
use App\Http\Controllers\Admin\OsTemplateController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PlacementGroupController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServerFirewallController;
use App\Http\Controllers\Admin\StorageController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookEndpointController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\RdnsController;
use Illuminate\Support\Facades\Route;

/*
| Admin routes. Loaded from routes/web.php inside the `admin.` group
| (auth, verified, 2fa).
*/

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('help', HelpController::class)->name('help');

Route::get('drifts', [DriftController::class, 'index'])->name('drifts.index');
Route::post('drifts/{drift}/accept', [DriftController::class, 'accept'])->name('drifts.accept');
Route::post('drifts/{drift}/mark-error', [DriftController::class, 'markError'])->name('drifts.mark-error');
Route::post('drifts/{drift}/apply-power', [DriftController::class, 'applyPower'])->name('drifts.apply-power');
Route::post('drifts/{drift}/dismiss', [DriftController::class, 'dismiss'])->name('drifts.dismiss');

Route::resource('locations', LocationController::class)->only(['index', 'store', 'update', 'destroy']);

Route::resource('clusters', ClusterController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
Route::post('clusters/{cluster}/sync', [ClusterController::class, 'sync'])->name('clusters.sync');
Route::post('clusters/{cluster}/permissions', [ClusterController::class, 'checkPermissions'])->name('clusters.permissions');
Route::patch('nodes/{node}', [NodeController::class, 'update'])->name('nodes.update');
Route::patch('storages/{storage}', [StorageController::class, 'update'])->name('storages.update');

Route::resource('ip-pools', IpPoolController::class)->only(['index', 'store', 'show', 'update', 'destroy'])
    ->parameters(['ip-pools' => 'ipPool']);
Route::get('ip-pools/{ipPool}/rdns.csv', [IpPoolRdnsController::class, 'export'])->name('ip-pools.rdns-export');
Route::post('ip-pools/{ipPool}/rdns/mark-applied', [IpPoolRdnsController::class, 'markApplied'])->name('ip-pools.rdns-mark-applied');
Route::resource('dns-providers', DnsProviderController::class)->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['dns-providers' => 'dnsProvider']);
Route::post('dns-providers/{dnsProvider}/test', [DnsProviderController::class, 'test'])->middleware('throttle:10,1')->name('dns-providers.test');
Route::post('ip-pools/{ipPool}/addresses', [IpAddressController::class, 'store'])->name('ip-addresses.store');
Route::post('ip-pools/{ipPool}/addresses/bulk', [IpAddressController::class, 'bulk'])->name('ip-addresses.bulk');
Route::post('ip-pools/{ipPool}/addresses/keep-only', [IpAddressController::class, 'keepOnly'])->name('ip-addresses.keep-only');
Route::patch('ip-addresses/{ipAddress}', [IpAddressController::class, 'update'])->name('ip-addresses.update');
Route::delete('ip-addresses/{ipAddress}', [IpAddressController::class, 'destroy'])->name('ip-addresses.destroy');

// The operating systems and versions OS templates are of (docs/adr/0026).
Route::post('operating-systems', [OperatingSystemController::class, 'store'])->name('operating-systems.store');
Route::put('operating-systems/{operatingSystem}', [OperatingSystemController::class, 'update'])->name('operating-systems.update');
Route::delete('operating-systems/{operatingSystem}', [OperatingSystemController::class, 'destroy'])->name('operating-systems.destroy');
Route::post('operating-systems/{operatingSystem}/versions', [OperatingSystemController::class, 'storeVersion'])->name('operating-systems.versions.store');
Route::delete('operating-systems/{operatingSystem}/versions/{version}', [OperatingSystemController::class, 'destroyVersion'])->scopeBindings()->name('operating-systems.versions.destroy');

Route::resource('os-templates', OsTemplateController::class)->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['os-templates' => 'osTemplate']);
Route::get('os-templates/{osTemplate}/os-release', [OsTemplateController::class, 'osRelease'])->middleware('throttle:20,1')->name('os-templates.os-release');
Route::get('os-templates/{osTemplate}/scripts', [GuestScriptController::class, 'index'])->name('os-templates.scripts');
Route::put('os-templates/{osTemplate}/scripts/{event}', [GuestScriptController::class, 'update'])->name('os-templates.scripts.update');
Route::delete('os-templates/{osTemplate}/scripts/{event}', [GuestScriptController::class, 'destroy'])->name('os-templates.scripts.destroy');
Route::post('os-templates/{osTemplate}/scripts/{event}/publish', [GuestScriptController::class, 'publish'])->name('os-templates.scripts.publish');
Route::delete('os-templates/{osTemplate}/scripts/{event}/draft', [GuestScriptController::class, 'discard'])->name('os-templates.scripts.discard');
Route::post('os-templates/{osTemplate}/scripts/{event}/run', [GuestScriptController::class, 'run'])->middleware('throttle:10,1')->name('os-templates.scripts.run');
Route::get('guest-script-logs/{guestScriptLog}', [GuestScriptController::class, 'log'])->name('guest-script-logs.show');

Route::resource('packages', PackageController::class)->only(['index', 'store', 'update', 'destroy']);
Route::resource('placement-groups', PlacementGroupController::class)->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['placement-groups' => 'placementGroup']);
// docs/adr/0035 §3
Route::resource('firewall-rulesets', FirewallRulesetController::class)->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['firewall-rulesets' => 'firewallRuleset']);

Route::get('apps', [AppController::class, 'index'])->name('apps.index');
Route::patch('apps/{appRecipe}', [AppController::class, 'update'])->name('apps.update');
Route::put('apps/{appRecipe}/systems', [AppController::class, 'systems'])->name('apps.systems');
// Recipe editor (docs/adr/0018).
Route::post('apps', [AppRecipeController::class, 'store'])->name('apps.store');
Route::post('apps/import', [AppRecipeController::class, 'import'])->middleware('throttle:20,1')->name('apps.import');
Route::get('apps/{appRecipe}/edit', [AppRecipeController::class, 'edit'])->name('apps.edit');
Route::get('apps/{appRecipe}/diff', [AppRecipeController::class, 'diff'])->name('apps.diff');
Route::get('apps/{appRecipe}/export', [AppRecipeController::class, 'export'])->middleware('throttle:30,1')->name('apps.export');
Route::post('apps/{appRecipe}/draft/import', [AppRecipeController::class, 'importDraft'])->middleware('throttle:20,1')->name('apps.draft.import');
Route::post('apps/{appRecipe}/copy', [AppRecipeController::class, 'copy'])->name('apps.copy');
Route::put('apps/{appRecipe}/draft', [AppRecipeController::class, 'saveDraft'])->name('apps.draft.save');
Route::delete('apps/{appRecipe}/draft', [AppRecipeController::class, 'discardDraft'])->name('apps.draft.discard');
Route::post('apps/{appRecipe}/publish', [AppRecipeController::class, 'publish'])->name('apps.publish');
Route::post('apps/{appRecipe}/revert', [AppRecipeController::class, 'revert'])->name('apps.revert');
Route::post('apps/{appRecipe}/trial', [AppRecipeController::class, 'trial'])->middleware('throttle:10,1')->name('apps.trial');
Route::delete('apps/{appRecipe}', [AppRecipeController::class, 'destroy'])->name('apps.destroy');
Route::get('app-logs/{appLog}', [AppLogController::class, 'show'])->middleware('throttle:120,1')->name('app-logs.show');
Route::post('app-installations/{appInstallation}/outputs', [AppController::class, 'outputs'])->middleware('throttle:20,1')->name('app-installations.outputs');
Route::post('app-installations/{appInstallation}/retry', [AppController::class, 'retry'])->middleware('throttle:10,1')->name('app-installations.retry');
Route::post('app-installations/{appInstallation}/cancel', [AppController::class, 'cancel'])->middleware('throttle:10,1')->name('app-installations.cancel');
Route::post('app-runs/{appRun}/cancel', [AppController::class, 'cancelRun'])->middleware('throttle:10,1')->name('app-runs.cancel');
Route::post('app-installations/{appInstallation}/uninstall', [AppController::class, 'uninstall'])->middleware('throttle:10,1')->name('app-installations.uninstall');
Route::post('app-installations/{appInstallation}/upgrade', [AppController::class, 'upgrade'])->middleware('throttle:10,1')->name('app-installations.upgrade');
Route::post('app-installations/{appInstallation}/actions/{action}', [AppController::class, 'action'])->where('action', '[a-z][a-z0-9_]{0,39}')->middleware('throttle:20,1')->name('app-installations.action');

Route::resource('users', UserController::class)->only(['index', 'store']);
Route::resource('billing-systems', BillingSystemController::class)->only(['index', 'store', 'update']);
Route::post('billing-systems/{billingSystem}/tokens', [ApiTokenController::class, 'store'])->name('billing-systems.tokens.store');
// Revoking ("destroy") keeps the token for the audit log; it can never be used again.
Route::delete('api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
Route::post('billing-systems/{billingSystem}/webhooks', [WebhookEndpointController::class, 'store'])->name('billing-systems.webhooks.store');
Route::get('webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'show'])->name('webhooks.show');
Route::put('webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->name('webhooks.update');
Route::delete('webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
Route::post('webhooks/{webhookEndpoint}/secret', [WebhookEndpointController::class, 'secret'])->name('webhooks.secret');
Route::post('webhooks/{webhookEndpoint}/ping', [WebhookEndpointController::class, 'ping'])->name('webhooks.ping');
Route::post('webhook-deliveries/{webhookDelivery}/redeliver', [WebhookEndpointController::class, 'redeliver'])->name('webhook-deliveries.redeliver');

// Adoption routes come before the resource so "adopt" isn't taken for a server UUID.
Route::get('servers/adopt', [AdoptionController::class, 'create'])->name('servers.adopt');
Route::post('servers/adopt', [AdoptionController::class, 'store'])->name('servers.adopt.store');
Route::resource('servers', ServerController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
Route::post('servers/{server}/suspend', [ServerController::class, 'suspend'])->name('servers.suspend');
Route::post('servers/{server}/unsuspend', [ServerController::class, 'unsuspend'])->name('servers.unsuspend');
Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])->middleware('throttle:20,1')->name('servers.power');
Route::post('servers/{server}/retry', [ServerController::class, 'retry'])->name('servers.retry');
Route::post('servers/{server}/rebuild', [ServerController::class, 'rebuild'])->middleware('throttle:10,1')->name('servers.rebuild');
Route::post('servers/{server}/verify', [ServerController::class, 'verify'])->middleware('throttle:10,1')->name('servers.verify');
Route::put('servers/{server}/firewall', [ServerFirewallController::class, 'update'])->middleware('throttle:20,1')->name('servers.firewall');
Route::post('servers/{server}/firewall/ruleset', [ServerFirewallController::class, 'ruleset'])->middleware('throttle:20,1')->name('servers.firewall.ruleset');
Route::post('servers/{server}/anti-spoofing', [ServerController::class, 'enableAntiSpoofing'])->middleware('throttle:10,1')->name('servers.enable-anti-spoofing');
Route::post('tasks/{task}/fail', [TaskController::class, 'fail'])->name('tasks.fail');
Route::post('servers/{server}/password', [ServerController::class, 'revealPassword'])->middleware('throttle:20,1')->name('servers.password');
Route::post('servers/{server}/rdns', RdnsController::class)->middleware('throttle:30,1')->name('servers.rdns');
Route::post('servers/{server}/reinstall', [ServerController::class, 'reinstall'])->middleware('throttle:10,1')->name('servers.reinstall');
Route::post('servers/{server}/resources', [ServerController::class, 'changeResources'])->middleware('throttle:10,1')->name('servers.resources');
Route::post('servers/{server}/addresses', [ServerController::class, 'changeAddresses'])->middleware('throttle:10,1')->name('servers.addresses');
Route::post('servers/{server}/addresses/reapply', [ServerController::class, 'reapplyAddresses'])->middleware('throttle:10,1')->name('servers.reapply-addresses');
Route::post('servers/{server}/hostname', [ServerController::class, 'changeHostname'])->middleware('throttle:10,1')->name('servers.hostname');
Route::post('servers/{server}/reset-password', [ServerController::class, 'resetPassword'])->middleware('throttle:10,1')->name('servers.reset-password');
Route::put('servers/{server}/test', [AppController::class, 'testServer'])->name('servers.test');
Route::post('servers/{server}/trial-reset', [AppController::class, 'resetTestServer'])->middleware('throttle:10,1')->name('servers.trial-reset');
Route::post('servers/{server}/apps', [AppController::class, 'install'])->middleware('throttle:10,1')->name('servers.apps');
Route::post('servers/{server}/app-entitlements', [AppEntitlementController::class, 'store'])->middleware('throttle:20,1')->name('servers.app-entitlements');
Route::delete('app-entitlements/{appEntitlement}', [AppEntitlementController::class, 'destroy'])->middleware('throttle:20,1')->name('app-entitlements.destroy');
Route::get('servers/{server}/console', ConsoleController::class)->middleware('throttle:20,1')->name('servers.console');
