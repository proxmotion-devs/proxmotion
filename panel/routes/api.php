<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ServerConsoleController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\ServerPasswordController;
use App\Http\Controllers\Api\V1\ServerRdnsController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

/*
| Billing API v1 (docs/adr/0008, docs/api/openapi.yaml), prefix /api/v1.
| Never make breaking changes here: add fields and endpoints instead.
*/

Route::middleware('api.scope:catalog:read')->group(function (): void {
    Route::get('packages', [CatalogController::class, 'packages'])->name('packages.index');
    Route::get('locations', [CatalogController::class, 'locations'])->name('locations.index');
    Route::get('os-templates', [CatalogController::class, 'osTemplates'])->name('os-templates.index');
});

Route::middleware('api.scope:servers:read')->group(function (): void {
    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
});

Route::middleware(['api.scope:servers:write', 'api.idempotent'])->group(function (): void {
    Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
    Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::post('servers/{server}/suspend', [ServerController::class, 'suspend'])->name('servers.suspend');
    Route::post('servers/{server}/unsuspend', [ServerController::class, 'unsuspend'])->name('servers.unsuspend');
    Route::post('servers/{server}/power', [ServerController::class, 'power'])->name('servers.power');
    Route::post('servers/{server}/reinstall', [ServerController::class, 'reinstall'])->name('servers.reinstall');
    Route::post('servers/{server}/package', [ServerController::class, 'changeResources'])->name('servers.package');
    Route::post('servers/{server}/primary-address', [ServerController::class, 'primaryAddress'])->name('servers.primary-address');
    Route::post('servers/{server}/hostname', [ServerController::class, 'hostname'])->name('servers.hostname');
    Route::post('servers/{server}/password', [ServerPasswordController::class, 'reset'])->name('servers.password.reset');
    Route::post('servers/{server}/rdns', ServerRdnsController::class)->name('servers.rdns');
});

Route::middleware('api.scope:servers:secrets')->group(function (): void {
    Route::get('servers/{server}/password', [ServerPasswordController::class, 'show'])->name('servers.password.show');
    Route::post('servers/{server}/console', ServerConsoleController::class)->name('servers.console');
});
