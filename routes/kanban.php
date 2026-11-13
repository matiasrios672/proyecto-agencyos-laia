<?php

use App\Http\Controllers\Kanban\KanbanController;
use App\Http\Controllers\Kanban\MyTasksController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo "kanban": tablero de tareas por cliente y "Mis tareas". Incluido desde routes/web.php.
Route::middleware('auth')->group(function () {
    Route::get('/mis-tareas', MyTasksController::class)->middleware('can:content.view')->name('tasks.mine');

    Route::prefix('/clientes/{client}/tablero')->name('kanban.')->group(function () {
        Route::get('/', [KanbanController::class, 'show'])->name('show');
        Route::post('/tarjetas', [KanbanController::class, 'store'])->name('cards.store');
        Route::put('/tarjetas/{card}', [KanbanController::class, 'update'])->name('cards.update');
        Route::post('/tarjetas/{card}/mover', [KanbanController::class, 'move'])->middleware('throttle:120,1')->name('cards.move');
        Route::delete('/tarjetas/{card}', [KanbanController::class, 'destroy'])->name('cards.destroy');
    });
});
