<?php

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\Security\ClientTrashController;
use App\Http\Controllers\Security\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo "security". Incluido desde routes/web.php.

// Invitados: recuperar contraseña y segundo paso del login.
Route::middleware('guest')->group(function () {
    Route::get('/olvide-mi-contrasena', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/olvide-mi-contrasena', [PasswordResetController::class, 'store'])->name('password.email');
    Route::get('/restablecer-contrasena/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/restablecer-contrasena', [PasswordResetController::class, 'update'])->name('password.update');

    Route::get('/verificacion-dos-pasos', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/verificacion-dos-pasos', [TwoFactorChallengeController::class, 'store'])->name('two-factor.verify');
});

Route::middleware('auth')->group(function () {
    // Verificación en dos pasos del propio usuario (se opera desde /perfil).
    Route::prefix('/perfil/2fa')->name('two-factor.')->group(function () {
        Route::post('/', [TwoFactorController::class, 'enable'])->name('enable');
        Route::post('/confirmar', [TwoFactorController::class, 'confirm'])->name('confirm');
        Route::delete('/pendiente', [TwoFactorController::class, 'cancel'])->name('cancel');
        Route::post('/codigos', [TwoFactorController::class, 'regenerate'])->name('regenerate');
        Route::delete('/', [TwoFactorController::class, 'disable'])->name('disable');
    });

    // Dispositivo perdido: Dirección / sysadmin restablecen el 2FA de un empleado.
    Route::post('/equipo/{user}/2fa/restablecer', [EmployeeController::class, 'resetTwoFactor'])
        ->middleware('can:employees.manage')->name('employees.two-factor-reset');

    // Papelera de clientes (clients.manage; la policy lo vuelve a validar).
    Route::middleware('can:clients.manage')->group(function () {
        Route::get('/clientes-eliminados', [ClientTrashController::class, 'index'])->name('clients.trashed');
        Route::post('/clientes-eliminados/{id}/restaurar', [ClientTrashController::class, 'restore'])->whereNumber('id')->name('clients.restore');
    });
});
