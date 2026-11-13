<?php

use Illuminate\Support\Facades\Route;

// Rutas del módulo "content". Incluido desde routes/web.php.

// ── Grillas mensuales de contenido (PDF) ────────────────────────────────────────────────────────────────────────
// PDF público de una grilla: solo con URL firmada y vigente (se comparte por WhatsApp). Sin sesión.
Route::get('/p/contenido/{grid}/pdf', [\App\Http\Controllers\ContentGridController::class, 'publicFile'])
    ->middleware(['signed', 'throttle:60,1'])->name('content.public_file');

Route::middleware('auth')->group(function () {
    Route::get('/contenido', [\App\Http\Controllers\ContentGridController::class, 'overview'])->name('content.overview');
    Route::get('/clientes/{client}/contenido', [\App\Http\Controllers\ContentGridController::class, 'clientIndex'])->name('content.client');
    Route::post('/clientes/{client}/contenido', [\App\Http\Controllers\ContentGridController::class, 'store'])->middleware('throttle:30,1')->name('content.store');
    Route::get('/contenido/{grid}/archivo', [\App\Http\Controllers\ContentGridController::class, 'file'])->name('content.file');
    Route::patch('/contenido/{grid}/estado', [\App\Http\Controllers\ContentGridController::class, 'updateStatus'])->name('content.status');
    Route::post('/contenido/{grid}/compartir', [\App\Http\Controllers\ContentGridController::class, 'share'])->middleware('throttle:60,1')->name('content.share');
    Route::delete('/contenido/{grid}', [\App\Http\Controllers\ContentGridController::class, 'destroy'])->name('content.destroy');
});
// ── fin: grillas de contenido ───────────────────────────────────────────────────────────────────────────────────
