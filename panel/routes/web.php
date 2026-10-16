<?php

use App\Http\Controllers\Client\ServerController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::post('servers/{server}/power/{action}', [ServerController::class, 'power'])->middleware('throttle:10,1')->name('servers.power');
    Route::get('servers/{server}/console', ConsoleController::class)->middleware('throttle:20,1')->name('servers.console');
});

Route::middleware(['auth', 'verified', 'role:admin', '2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(__DIR__.'/admin.php');

require __DIR__.'/settings.php';
