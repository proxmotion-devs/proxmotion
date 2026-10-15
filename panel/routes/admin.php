<?php

use App\Http\Controllers\Admin\ClusterController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IpAddressController;
use App\Http\Controllers\Admin\IpPoolController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\NodeController;
use App\Http\Controllers\Admin\OsTemplateController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\StorageController;
use Illuminate\Support\Facades\Route;

/*
| Admin routes. Loaded from routes/web.php inside the `admin.` group
| (auth, verified, role:admin, 2fa).
*/

Route::get('/', DashboardController::class)->name('dashboard');

Route::resource('locations', LocationController::class)->only(['index', 'store', 'update', 'destroy']);

Route::resource('clusters', ClusterController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
Route::post('clusters/{cluster}/sync', [ClusterController::class, 'sync'])->name('clusters.sync');
Route::patch('nodes/{node}', [NodeController::class, 'update'])->name('nodes.update');
Route::patch('storages/{storage}', [StorageController::class, 'update'])->name('storages.update');

Route::resource('ip-pools', IpPoolController::class)->only(['index', 'store', 'show', 'update', 'destroy'])
    ->parameters(['ip-pools' => 'ipPool']);
Route::post('ip-pools/{ipPool}/addresses', [IpAddressController::class, 'store'])->name('ip-addresses.store');
Route::patch('ip-addresses/{ipAddress}', [IpAddressController::class, 'update'])->name('ip-addresses.update');
Route::delete('ip-addresses/{ipAddress}', [IpAddressController::class, 'destroy'])->name('ip-addresses.destroy');

Route::resource('os-templates', OsTemplateController::class)->only(['index', 'store', 'update', 'destroy'])
    ->parameters(['os-templates' => 'osTemplate']);

Route::resource('packages', PackageController::class)->only(['index', 'store', 'update', 'destroy']);
