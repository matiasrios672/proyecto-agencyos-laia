<?php

use App\Http\Controllers\LaiaTimeController;
use Illuminate\Support\Facades\Route;

// Integraciones externas. Incluido desde routes/web.php.

Route::middleware('auth')->group(function () {
    // LaiaTime (turnos): ver = finance.view · actuar = finance.manage.
    Route::middleware('can:finance.view')->group(function () {
        Route::get('/admin/laiatime', [LaiaTimeController::class, 'index'])->name('laiatime.index');
        Route::post('/admin/laiatime/probar', [LaiaTimeController::class, 'ping'])->middleware('throttle:10,1')->name('laiatime.ping');
        Route::get('/clientes/{client}/laiatime', [LaiaTimeController::class, 'client'])->name('laiatime.client');
    });

    Route::middleware('can:finance.manage')->group(function () {
        Route::post('/admin/laiatime/sincronizar', [LaiaTimeController::class, 'sync'])->middleware('throttle:6,1')->name('laiatime.sync');
        Route::post('/admin/laiatime/instancias/{instance}/suspender', [LaiaTimeController::class, 'suspend'])
            ->where('instance', '[A-Za-z0-9._\-]+')->middleware('throttle:20,1')->name('laiatime.suspend');
        Route::post('/admin/laiatime/instancias/{instance}/reactivar', [LaiaTimeController::class, 'reactivate'])
            ->where('instance', '[A-Za-z0-9._\-]+')->middleware('throttle:20,1')->name('laiatime.reactivate');
    });
});
