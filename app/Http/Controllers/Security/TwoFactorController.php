<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Alta, confirmación, desactivación y códigos de recuperación del 2FA del propio usuario (se opera desde Perfil). */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /** Empieza el alta: genera un secreto pendiente. No se activa hasta confirmar con un código. */
    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactorEnabled(), 422, 'La verificación en dos pasos ya está activa.');

        $user->forceFill(['two_factor_secret' => $this->twoFactor->newSecret(), 'two_factor_confirmed_at' => null])->save();

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $user = $request->user();

        $this->throttle($request, 'confirm');

        $codes = $this->twoFactor->confirmSetup($user, $data['code']);
        if ($codes === null) {
            RateLimiter::hit($this->key($request, 'confirm'), 60);

            throw ValidationException::withMessages(['code' => 'El código no es correcto. Revisá que la hora de tu celular esté en automático.']);
        }

        RateLimiter::clear($this->key($request, 'confirm'));
        activity('security')->performedOn($user)->causedBy($user)->withProperties(['ip' => $request->ip()])->log('Verificación en dos pasos activada');

        // Los códigos se muestran una sola vez (flash): después solo se sabe cuántos quedan.
        return back()->with('recovery_codes', $codes)->with('success', 'Verificación en dos pasos activada.');
    }

    /** Cancela un alta sin confirmar (no hay nada activo que proteger, no pide contraseña). */
    public function cancel(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPendingTwoFactor(), 422, 'No hay un alta pendiente.');

        $this->twoFactor->disable($user);

        return back();
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 422, 'Primero activá la verificación en dos pasos.');
        $this->confirmPassword($request);

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        activity('security')->performedOn($user)->causedBy($user)->withProperties(['ip' => $request->ip()])->log('Códigos de recuperación regenerados');

        return back()->with('recovery_codes', $codes)->with('success', 'Códigos nuevos generados. Los anteriores ya no sirven.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 422, 'La verificación en dos pasos no está activa.');
        $this->confirmPassword($request);

        // Si la política exige 2FA para su rol, no puede quedarse sin él.
        if (TwoFactor::requiredFor($user)) {
            throw ValidationException::withMessages(['password' => 'Tu rol exige verificación en dos pasos: no se puede desactivar.']);
        }

        $this->twoFactor->disable($user);
        activity('security')->performedOn($user)->causedBy($user)->withProperties(['ip' => $request->ip()])->log('Verificación en dos pasos desactivada');

        return back()->with('success', 'Verificación en dos pasos desactivada.');
    }

    /** Operaciones sensibles: piden la contraseña actual (con tope de intentos). */
    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string']]);
        $this->throttle($request, 'password');

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            RateLimiter::hit($this->key($request, 'password'), 60);

            throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
        }

        RateLimiter::clear($this->key($request, 'password'));
    }

    private function throttle(Request $request, string $scope): void
    {
        $key = $this->key($request, $scope);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                $scope === 'confirm' ? 'code' : 'password' => 'Demasiados intentos. Probá de nuevo en '.RateLimiter::availableIn($key).' segundos.',
            ]);
        }
    }

    private function key(Request $request, string $scope): string
    {
        return "two-factor-{$scope}|{$request->user()->id}|{$request->ip()}";
    }
}
