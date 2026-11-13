<?php

namespace App\Http\Middleware;

use App\Support\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si el sysadmin activó "exigir verificación en dos pasos" (security.require_2fa_privileged), el Superadmin y
 * Dirección sin 2FA solo pueden usar el Perfil (para activarlo), las notificaciones y salir de la sesión.
 */
class EnsureTwoFactorSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasTwoFactorEnabled() || ! TwoFactor::requiredFor($user)) {
            return $next($request);
        }

        if ($request->routeIs('profile.*', 'two-factor.*', 'logout', 'notifications.*')) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Activá la verificación en dos pasos para continuar.'], 423);
        }

        return redirect()->route('profile.edit')->with('error', 'Tu rol requiere verificación en dos pasos. Activala para seguir usando Agency OS.');
    }
}
