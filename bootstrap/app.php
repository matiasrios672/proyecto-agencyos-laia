<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Autorización de canales privados (ruta /broadcasting/auth, con sesión web).
    ->withBroadcasting(__DIR__.'/../routes/channels.php')
    ->withMiddleware(function (Middleware $middleware): void {
        // AuthenticateSession: al cambiar la contraseña (perfil o restablecimiento) se cierran todas las demás sesiones.
        // EnsureTwoFactorSetup: si la política lo exige, Superadmin y Dirección sin 2FA solo acceden al Perfil.
        $middleware->web(append: [
            \App\Http\Middleware\AuthenticateSessionForUser::class,
            EnsureUserIsActive::class,
            \App\Http\Middleware\EnsureTwoFactorSetup::class,
            HandleInertiaRequests::class,
        ]);
        // Detrás del proxy de Coolify (TLS terminado afuera): confiar en X-Forwarded-* para URLs https y URLs firmadas.
        // TRUSTED_PROXIES: "*" (por defecto) o lista de IP/CIDR separadas por coma.
        $trusted = trim((string) env('TRUSTED_PROXIES', '*'));
        $middleware->trustProxies(at: in_array($trusted, ['*', '**'], true)
            ? $trusted
            : array_values(array_filter(array_map('trim', explode(',', $trusted)))));
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
