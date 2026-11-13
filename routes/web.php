<?php

use App\Http\Controllers\Admin\PermissionMatrixController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientServiceController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ClientTeamController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VaultItemController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

// Invitación de empleados (URL firmada). No requiere sesión.
Route::get('/invitacion/{user}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/invitacion/{user}', [InvitationController::class, 'store'])->name('invitation.store');

// PDF público de un presupuesto: solo con URL firmada y vigente (se comparte por email / WhatsApp).
Route::get('/p/presupuesto/{quote}/pdf', [QuoteController::class, 'publicPdf'])
    ->middleware(['signed', 'throttle:60,1'])->name('quotes.public_pdf');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    // Perfil propio y notificaciones internas.
    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/perfil/contrasena', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');
    Route::get('/notificaciones', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notificaciones/leer', [NotificationController::class, 'markAllRead'])->name('notifications.read_all');
    Route::post('/notificaciones/{id}/leer', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Clientes (autorización por ClientPolicy dentro de cada controlador).
    Route::resource('clientes', ClientController::class)->parameters(['clientes' => 'client'])
        ->names('clients');
    Route::put('/clientes/{client}/equipo', [ClientTeamController::class, 'update'])->name('clients.team');

    // Finanzas: ver = finance.view · modificar = finance.manage. Las liquidaciones también las ve cada empleado (las suyas).
    Route::middleware('can:finance.view')->group(function () {
        Route::get('/finanzas', FinanceController::class)->name('finance.dashboard');
        Route::get('/cobros', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/egresos', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/comunicados', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/comunicados/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
    });
    Route::middleware('can:finance.manage')->group(function () {
        Route::post('/cobros', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::post('/cobros/generar', [InvoiceController::class, 'generate'])->middleware('throttle:10,1')->name('invoices.generate');
        Route::post('/cobros/{invoice}/pagar', [InvoiceController::class, 'pay'])->name('invoices.pay');
        Route::post('/cobros/{invoice}/anular', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('/liquidaciones/liquidar', [PayoutController::class, 'settle'])->name('payouts.settle');
        Route::post('/egresos', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::put('/egresos/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('/egresos/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::post('/comunicados', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::post('/comunicados/{announcement}/enviar', [AnnouncementController::class, 'send'])->middleware('throttle:10,1')->name('announcements.send');
        Route::delete('/comunicados/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
    });
    Route::get('/liquidaciones', [PayoutController::class, 'index'])->name('payouts.index');

    // Servicios contratados por el cliente (monto, vencimiento, empleado y reparto).
    Route::post('/clientes/{client}/servicios', [ClientServiceController::class, 'store'])->name('client-services.store');
    Route::put('/clientes/{client}/servicios/{service}', [ClientServiceController::class, 'update'])->name('client-services.update');
    Route::delete('/clientes/{client}/servicios/{service}', [ClientServiceController::class, 'destroy'])->name('client-services.destroy');

    // Catálogo de servicios y presupuestos.
    Route::prefix('/catalogo')->name('catalog.')->group(function () {
        Route::get('/', [CatalogController::class, 'index'])->name('index');
        Route::post('/', [CatalogController::class, 'store'])->name('store');
        Route::put('/{service}', [CatalogController::class, 'update'])->name('update');
        Route::delete('/{service}', [CatalogController::class, 'destroy'])->name('destroy');
    });
    Route::resource('presupuestos', QuoteController::class)->parameters(['presupuestos' => 'quote'])
        ->names('quotes');
    Route::prefix('/presupuestos/{quote}')->name('quotes.')->group(function () {
        Route::get('/pdf', [QuoteController::class, 'pdf'])->name('pdf');
        Route::post('/enviar', [QuoteController::class, 'send'])->middleware('throttle:20,1')->name('send');
        Route::post('/rechazar', [QuoteController::class, 'reject'])->name('reject');
        Route::post('/convertir', [QuoteController::class, 'convert'])->name('convert');
    });

    // Bóveda cifrada de credenciales.
    Route::prefix('/clientes/{client}/boveda')->name('vault.')->group(function () {
        Route::post('/', [VaultItemController::class, 'store'])->name('store');
        Route::put('/{item}', [VaultItemController::class, 'update'])->name('update');
        Route::delete('/{item}', [VaultItemController::class, 'destroy'])->name('destroy');
        Route::post('/{item}/revelar', [VaultItemController::class, 'reveal'])->middleware('throttle:30,1')->name('reveal');
    });

    // Alta / baja de empleados.
    Route::middleware('can:employees.manage')->prefix('/equipo')->name('employees.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::put('/{user}', [EmployeeController::class, 'update'])->name('update');
        Route::post('/{user}/invitacion', [EmployeeController::class, 'resendInvitation'])->name('invite');
        Route::post('/{user}/estado', [EmployeeController::class, 'toggleActive'])->name('toggle');
    });

    // Panel exclusivo del Superadministrador Técnico.
    Route::middleware('role:sysadmin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/configuracion', [SettingsController::class, 'index'])->name('settings');
        Route::put('/configuracion', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('/permisos', [PermissionMatrixController::class, 'index'])->name('permissions');
        Route::post('/permisos/rol', [PermissionMatrixController::class, 'toggleRole'])->name('permissions.role');
        Route::post('/permisos/usuario/{user}', [PermissionMatrixController::class, 'toggleUser'])->name('permissions.user');

        Route::get('/sistema', [SystemStatusController::class, 'index'])->name('system');
        Route::post('/sistema/reintentar', [SystemStatusController::class, 'retryFailed'])->name('system.retry');
        Route::delete('/sistema/fallidos', [SystemStatusController::class, 'flushFailed'])->name('system.flush');
    });
});

// Rutas por módulo (cada archivo tiene un único responsable; ver .claude/agent-rules.md).
foreach (['security', 'content', 'integrations', 'messaging', 'kanban'] as $module) {
    require __DIR__."/{$module}.php";
}
