<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\RecoveryCodeUsed;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Segundo paso del login. Hasta acá solo se validó la contraseña: en la sesión queda únicamente el id pendiente
 * (con vencimiento corto) y NO hay usuario autenticado.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'two_factor_pending';

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return redirect()->route('login')->with('error', 'Tu intento de ingreso venció. Volvé a ingresar tu contraseña.');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect()->route('login')->with('error', 'Tu intento de ingreso venció. Volvé a ingresar tu contraseña.');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);

        // La cuenta pudo darse de baja entre el paso 1 y el 2.
        if (! User::where('is_active', true)->whereKey($pending['id'])->exists()) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('login')->with('error', 'Tu cuenta no está activa. Contactá a Dirección.');
        }

        $key = "two-factor|{$pending['id']}|{$request->ip()}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Demasiados intentos. Probá de nuevo en '.RateLimiter::availableIn($key).' segundos.',
            ]);
        }

        $method = $twoFactor->attempt($pending['id'], $data['code']);

        if ($method === null) {
            RateLimiter::hit($key, 60);
            activity('security')->withProperties(['user_id' => $pending['id'], 'ip' => $request->ip()])->log('Código de verificación incorrecto');

            throw ValidationException::withMessages(['code' => 'El código no es correcto o ya fue usado.']);
        }

        RateLimiter::clear($key);

        $user = User::where('is_active', true)->findOrFail($pending['id']);
        $request->session()->forget(self::SESSION_KEY);
        Auth::login($user, $pending['remember']);
        $request->session()->regenerate();

        activity('security')->performedOn($user)->causedBy($user)->withProperties(['ip' => $request->ip(), 'method' => $method])
            ->log($method === 'recovery' ? 'Ingreso con código de recuperación' : 'Ingreso con verificación en dos pasos');

        if ($method === 'recovery') {
            $user->notify(new RecoveryCodeUsed($twoFactor->remainingRecoveryCodes($user)));
        }

        return redirect()->intended('/');
    }

    /** @return array{id: int, remember: bool}|null */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return ['id' => (int) $pending['id'], 'remember' => (bool) ($pending['remember'] ?? false)];
    }
}
