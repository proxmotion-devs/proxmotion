<?php

use App\Domain\Reports\Reports;
use App\Domain\Settings\PanelSettings;
use App\Http\Controllers\Admin\AdoptionController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AppController;
use App\Http\Controllers\Admin\AppEntitlementController;
use App\Http\Controllers\Admin\AppLogController;
use App\Http\Controllers\Admin\AppPackageController;
use App\Http\Controllers\Admin\AppRecipeController;
use App\Http\Controllers\Admin\BillingSystemController;
use App\Http\Controllers\Admin\ClusterController;
use App\Http\Controllers\Admin\CustomScriptController;
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
use App\Http\Controllers\Admin\NodeDiskController;
use App\Http\Controllers\Admin\OperatingSystemController;
use App\Http\Controllers\Admin\OsTemplateController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PanelSettingsController;
use App\Http\Controllers\Admin\PlacementGroupController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServerFirewallController;
use App\Http\Controllers\Admin\ServerMoveController;
use App\Http\Controllers\Admin\ServerReinstallLimitController;
use App\Http\Controllers\Admin\ServerSnapshotController;
use App\Http\Controllers\Admin\ServerSshKeyController;
use App\Http\Controllers\Admin\ServerStatsController;
use App\Http\Controllers\Admin\ServerUsageController;
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
Route::get('settings', [PanelSettingsController::class, 'index'])->name('settings.index');
Route::post('settings/notifications/test', [PanelSettingsController::class, 'test'])->name('settings.test-email')
    ->middleware('throttle:3,1');
Route::get('settings/{category}', [PanelSettingsController::class, 'edit'])->name('settings.edit')
    ->whereIn('category', array_keys(PanelSettings::CATEGORIES));
Route::put('settings/{category}', [PanelSettingsController::class, 'update'])->name('settings.update')
    ->whereIn('category', array_keys(array_filter(PanelSettings::CATEGORIES)));

// Reports (docs/adr/0046): each table also as CSV.
Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show')
    ->whereIn('report', array_keys(Reports::PAGES));
Route::get('reports/{report}/{table}.csv', [ReportController::class, 'csv'])->name('reports.csv')
    ->whereIn('report', array_keys(Reports::PAGES))
    ->where('table', '[a-z_]+');

Route::get('drifts', [DriftController::class, 'index'])->name('drifts.index');
Route::post('drifts/{drift}/accept', [DriftController::class, 'accept'])->name('drifts.accept');
Route::post('drifts/{drift}/mark-error', [DriftController::class, 'markError'])->name('drifts.mark-error');
Route::post('drifts/{drift}/apply-power', [DriftController::class, 'applyPower'])->name('drifts.apply-power');
Route::post('drifts/{drift}/dismiss', [DriftController::class, 'dismiss'])->name('drifts.dismiss');

Route::resource('locations', LocationController::class)->only(['index', 'store', 'update', 'destroy']);

Route::resource('clusters', ClusterController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
Route::post('clusters/{cluster}/sync', [ClusterController::class, 'sync'])->name('clusters.sync');
Route::post('clusters/{cluster}/permissions', [ClusterController::class, 'checkPermissions'])->name('clusters.permissions');
// The token servers from other clusters are moved in with (docs/adr/0037 §5).
Route::put('clusters/{cluster}/incoming', [ClusterController::class, 'updateIncoming'])->middleware('throttle:10,1')->name('clusters.incoming');
Route::post('clusters/{cluster}/incoming/permissions', [ClusterController::class, 'checkIncomingPermissions'])->name('clusters.incoming.permissions');
Route::patch('nodes/{node}', [NodeController::class, 'update'])->name('nodes.update');
Route::patch('storages/{storage}', [StorageController::class, 'update'])->name('storages.update');
Route::delete('node-disks/{disk}', [NodeDiskController::class, 'destroy'])->name('node-disks.destroy');

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
// Administrators' own scripts, run by hand or at events (docs/adr/0043).
Route::scopeBindings()->group(function () {
    Route::post('os-templates/{osTemplate}/custom-scripts', [CustomScriptController::class, 'store'])->name('os-templates.custom-scripts.store');
    Route::patch('os-templates/{osTemplate}/custom-scripts/{customScript}', [CustomScriptController::class, 'update'])->name('os-templates.custom-scripts.update');
    Route::delete('os-templates/{osTemplate}/custom-scripts/{customScript}', [CustomScriptController::class, 'destroy'])->name('os-templates.custom-scripts.destroy');
    Route::put('os-templates/{osTemplate}/custom-scripts/{customScript}/draft', [CustomScriptController::class, 'saveDraft'])->name('os-templates.custom-scripts.draft');
    Route::delete('os-templates/{osTemplate}/custom-scripts/{customScript}/draft', [CustomScriptController::class, 'discard'])->name('os-templates.custom-scripts.discard');
    Route::post('os-templates/{osTemplate}/custom-scripts/{customScript}/publish', [CustomScriptController::class, 'publish'])->name('os-templates.custom-scripts.publish');
    Route::post('os-templates/{osTemplate}/custom-scripts/{customScript}/move', [CustomScriptController::class, 'move'])->name('os-templates.custom-scripts.move');
    Route::post('os-templates/{osTemplate}/custom-scripts/{customScript}/run', [CustomScriptController::class, 'run'])->middleware('throttle:10,1')->name('os-templates.custom-scripts.run');
});

Route::resource('packages', PackageController::class)->only(['index', 'store', 'update', 'destroy']);
Route::resource('placement-groups', PlacementGroupController::class)->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['placement-groups' => 'placementGroup']);
// docs/adr/0035 §3
Route::resource('firewall-rulesets', FirewallRulesetController::class)->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['firewall-rulesets' => 'firewallRuleset']);

Route::get('apps', [AppController::class, 'index'])->name('apps.index');
// App packages (docs/adr/0042): upload a .proxapp, look at what it changes, install it.
Route::post('apps/packages', [AppPackageController::class, 'store'])->middleware('throttle:20,1')->name('apps.packages.store');
Route::get('apps/packages/{package}', [AppPackageController::class, 'show'])->whereUuid('package')->name('apps.packages.show');
Route::post('apps/packages/{package}', [AppPackageController::class, 'install'])->whereUuid('package')->middleware('throttle:20,1')->name('apps.packages.install');
Route::delete('apps/{appRecipe}/package', [AppPackageController::class, 'remove'])->middleware('throttle:20,1')->name('apps.packages.remove');
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
// Live figures of the server page, which also mark it as watched (docs/adr/0036).
Route::get('servers/{server}/stats', ServerStatsController::class)->middleware('throttle:30,1')->name('servers.stats');
// Usage history of the server page's Usage tab (docs/adr/0050).
Route::get('servers/{server}/usage', ServerUsageController::class)->middleware('throttle:60,1')->name('servers.usage');
Route::post('servers/{server}/retry', [ServerController::class, 'retry'])->name('servers.retry');
Route::post('servers/{server}/rebuild', [ServerController::class, 'rebuild'])->middleware('throttle:10,1')->name('servers.rebuild');
Route::post('servers/{server}/verify', [ServerController::class, 'verify'])->middleware('throttle:10,1')->name('servers.verify');
// A manual custom script of the server's OS template (docs/adr/0043).
Route::post('servers/{server}/scripts/{customScript}/run', [CustomScriptController::class, 'runOnServer'])->middleware('throttle:10,1')->name('servers.scripts.run');
Route::put('servers/{server}/firewall', [ServerFirewallController::class, 'update'])->middleware('throttle:20,1')->name('servers.firewall');
Route::post('servers/{server}/firewall/ruleset', [ServerFirewallController::class, 'ruleset'])->middleware('throttle:20,1')->name('servers.firewall.ruleset');
Route::put('servers/{server}/ssh-keys', [ServerSshKeyController::class, 'update'])->middleware('throttle:20,1')->name('servers.ssh-keys');
// The keys really inside the server, read through the guest agent (docs/adr/0049 §11).
Route::get('servers/{server}/ssh-keys/guest', [ServerSshKeyController::class, 'guest'])->middleware('throttle:20,1')->name('servers.ssh-keys.guest');
// Snapshots (docs/adr/0051).
Route::post('servers/{server}/snapshots', [ServerSnapshotController::class, 'store'])->middleware('throttle:20,1')->name('servers.snapshots.store');
Route::post('servers/{server}/snapshots/{snapshot}/rollback', [ServerSnapshotController::class, 'rollback'])->middleware('throttle:10,1')->name('servers.snapshots.rollback');
Route::delete('servers/{server}/snapshots/{snapshot}', [ServerSnapshotController::class, 'destroy'])->middleware('throttle:20,1')->name('servers.snapshots.destroy');
Route::post('servers/{server}/anti-spoofing', [ServerController::class, 'enableAntiSpoofing'])->middleware('throttle:10,1')->name('servers.enable-anti-spoofing');
Route::post('tasks/{task}/fail', [TaskController::class, 'fail'])->name('tasks.fail');
Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
Route::post('servers/{server}/password', [ServerController::class, 'revealPassword'])->middleware('throttle:20,1')->name('servers.password');
Route::post('servers/{server}/rdns', RdnsController::class)->middleware('throttle:30,1')->name('servers.rdns');
Route::post('servers/{server}/reinstall', [ServerController::class, 'reinstall'])->middleware('throttle:10,1')->name('servers.reinstall');
Route::post('servers/{server}/resources', [ServerController::class, 'changeResources'])->middleware('throttle:10,1')->name('servers.resources');
Route::post('servers/{server}/addresses', [ServerController::class, 'changeAddresses'])->middleware('throttle:10,1')->name('servers.addresses');
Route::post('servers/{server}/addresses/reapply', [ServerController::class, 'reapplyAddresses'])->middleware('throttle:10,1')->name('servers.reapply-addresses');
Route::post('servers/{server}/hostname', [ServerController::class, 'changeHostname'])->middleware('throttle:10,1')->name('servers.hostname');
Route::post('servers/{server}/reset-password', [ServerController::class, 'resetPassword'])->middleware('throttle:10,1')->name('servers.reset-password');
// Moving servers to other nodes, clusters and locations (docs/adr/0037).
Route::post('servers/{server}/move', [ServerMoveController::class, 'store'])->middleware('throttle:10,1')->name('servers.move');
Route::post('servers/{server}/moves/{move}/finish', [ServerMoveController::class, 'finish'])->middleware('throttle:10,1')->name('servers.moves.finish');
Route::put('servers/{server}/test', [AppController::class, 'testServer'])->name('servers.test');
// Violations of traffic quotas and usage limits are recorded but never acted on (docs/adr/0039).
Route::put('servers/{server}/usage-exempt', [ServerController::class, 'usageExempt'])->name('servers.usage-exempt');
Route::put('servers/{server}/reinstall-limit', [ServerReinstallLimitController::class, 'update'])->name('servers.reinstall-limit');
Route::post('servers/{server}/reinstall-limit/reset', [ServerReinstallLimitController::class, 'reset'])->name('servers.reinstall-limit.reset');
Route::post('servers/{server}/trial-reset', [AppController::class, 'resetTestServer'])->middleware('throttle:10,1')->name('servers.trial-reset');
Route::post('servers/{server}/apps', [AppController::class, 'install'])->middleware('throttle:10,1')->name('servers.apps');
Route::post('servers/{server}/app-entitlements', [AppEntitlementController::class, 'store'])->middleware('throttle:20,1')->name('servers.app-entitlements');
Route::delete('app-entitlements/{appEntitlement}', [AppEntitlementController::class, 'destroy'])->middleware('throttle:20,1')->name('app-entitlements.destroy');
Route::get('servers/{server}/console', ConsoleController::class)->middleware('throttle:20,1')->name('servers.console');
