<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * `AuthenticateSession` de Laravel (cierra las demás sesiones cuando cambia la contraseña) que además recuerda de
 * qué usuario es la sesión. Si el usuario autenticado cambia dentro de la misma sesión, el hash guardado era de
 * otra persona: se descarta en vez de expulsar. En un navegador esto no ocurre nunca (cada login abre una sesión
 * nueva); sí pasa en los tests, donde `actingAs` alterna usuarios sobre una sesión compartida.
 */
class AuthenticateSessionForUser extends AuthenticateSession
{
    private const OWNER_KEY = 'auth_session_owner';

    public function handle($request, Closure $next)
    {
        /** @var Request $request */
        $user = $request->hasSession() ? $request->user() : null;

        if ($user !== null) {
            $owner = $request->session()->get(self::OWNER_KEY);

            if ($owner !== null && $owner !== $user->getAuthIdentifier()) {
                $request->session()->forget('password_hash_'.$this->auth->getDefaultDriver());
            }

            $request->session()->put(self::OWNER_KEY, $user->getAuthIdentifier());
        }

        return parent::handle($request, $next);
    }
}
