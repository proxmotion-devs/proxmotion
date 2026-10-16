<?php

use App\Http\Controllers\Admin\AdoptionController;
use App\Http\Controllers\Admin\ClusterController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IpAddressController;
use App\Http\Controllers\Admin\IpPoolController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\NodeController;
use App\Http\Controllers\Admin\OsTemplateController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\StorageController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ConsoleController;
use Illuminate\Support\Facades\Route;

/*
| Admin routes. Loaded from routes/web.php inside the `admin.` group
| (auth, verified, role:admin, 2fa).
*/

Route::get('/', DashboardController::class)->name('dashboard');

Route::resource('locations', LocationController::class)->only(['index', 'store', 'update', 'destroy']);

Route::resource('clusters', ClusterController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
Route::post('clusters/{cluster}/sync', [ClusterController::class, 'sync'])->name('clusters.sync');
Route::post('clusters/{cluster}/permissions', [ClusterController::class, 'checkPermissions'])->name('clusters.permissions');
Route::patch('nodes/{node}', [NodeController::class, 'update'])->name('nodes.update');
Route::patch('storages/{storage}', [StorageController::class, 'update'])->name('storages.update');

Route::resource('ip-pools', IpPoolController::class)->only(['index', 'store', 'show', 'update', 'destroy'])
    ->parameters(['ip-pools' => 'ipPool']);
Route::post('ip-pools/{ipPool}/addresses', [IpAddressController::class, 'store'])->name('ip-addresses.store');
Route::post('ip-pools/{ipPool}/addresses/bulk', [IpAddressController::class, 'bulk'])->name('ip-addresses.bulk');
Route::post('ip-pools/{ipPool}/addresses/keep-only', [IpAddressController::class, 'keepOnly'])->name('ip-addresses.keep-only');
Route::patch('ip-addresses/{ipAddress}', [IpAddressController::class, 'update'])->name('ip-addresses.update');
Route::delete('ip-addresses/{ipAddress}', [IpAddressController::class, 'destroy'])->name('ip-addresses.destroy');

Route::resource('os-templates', OsTemplateController::class)->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['os-templates' => 'osTemplate']);

Route::resource('packages', PackageController::class)->only(['index', 'store', 'update', 'destroy']);

Route::resource('users', UserController::class)->only(['index', 'store']);

// Adoption routes come before the resource so "adopt" isn't taken for a server UUID.
Route::get('servers/adopt', [AdoptionController::class, 'create'])->name('servers.adopt');
Route::post('servers/adopt', [AdoptionController::class, 'store'])->name('servers.adopt.store');
Route::resource('servers', ServerController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
Route::post('servers/{server}/suspend', [ServerController::class, 'suspend'])->name('servers.suspend');
Route::post('servers/{server}/unsuspend', [ServerController::class, 'unsuspend'])->name('servers.unsuspend');
Route::post('servers/{server}/retry', [ServerController::class, 'retry'])->name('servers.retry');
Route::post('servers/{server}/password', [ServerController::class, 'revealPassword'])->name('servers.password');
Route::get('servers/{server}/console', ConsoleController::class)->middleware('throttle:20,1')->name('servers.console');
