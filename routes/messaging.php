<?php

use App\Http\Controllers\Messaging\ConversationController;
use App\Http\Controllers\Messaging\InboxController;
use App\Http\Controllers\Messaging\MessageController;
use Illuminate\Support\Facades\Route;

// Mensajería: chats directos e hilos por cliente. La página es /mensajes; el resto es la API JSON que usa la interfaz.
// Cada endpoint vuelve a autorizar el acceso a la conversación (ver Conversation::isAccessibleBy).
Route::middleware(['auth', 'can:messages.use'])->prefix('/mensajes')->name('messages.')->group(function () {
    Route::get('/', [InboxController::class, 'page'])->name('index');

    Route::prefix('/api')->group(function () {
        Route::get('/conversaciones', [InboxController::class, 'index'])->name('conversations');
        Route::get('/no-leidos', [InboxController::class, 'unread'])->name('unread');
        Route::post('/directa', [ConversationController::class, 'direct'])->name('direct');
        Route::post('/hilos', [ConversationController::class, 'thread'])->name('thread');
        Route::get('/conversaciones/{conversation}', [ConversationController::class, 'show'])->name('show');
        Route::post('/conversaciones/{conversation}/leer', [ConversationController::class, 'read'])->name('read');
        Route::post('/conversaciones/{conversation}/mensajes', [MessageController::class, 'store'])
            ->middleware('throttle:30,1')->name('send');
        Route::put('/mensajes/{message}', [MessageController::class, 'update'])->middleware('throttle:60,1')->name('update');
        Route::delete('/mensajes/{message}', [MessageController::class, 'destroy'])->middleware('throttle:60,1')->name('destroy');
    });
});
