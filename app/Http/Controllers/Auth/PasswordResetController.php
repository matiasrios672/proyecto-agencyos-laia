<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\EmployeeInvitation;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Recuperación de contraseña: pedido del enlace (sin revelar si el email existe) y restablecimiento con token de un solo uso. */
class PasswordResetController extends Controller
{
    /** Misma respuesta exista o no la cuenta: no permite averiguar qué emails están registrados. */
    private const SENT = 'Si el email corresponde a una cuenta activa, te enviamos un enlace para restablecer la contraseña. Revisá también la carpeta de spam.';

    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:160']]);
        $email = str($data['email'])->lower()->toString();

        $key = "password-reset|{$email}|{$request->ip()}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Hiciste demasiados pedidos. Probá de nuevo en '.RateLimiter::availableIn($key).' segundos.',
            ]);
        }
        RateLimiter::hit($key, 900);

        $user = User::where('email', $email)->where('is_active', true)->first();

        if ($user === null) {
            // Nada que enviar (no existe, está dado de baja o eliminado), pero la respuesta es idéntica.
        } elseif ($user->password === null) {
            // Todavía no aceptó la invitación: no hay contraseña que restablecer; se le reenvía la invitación firmada.
            $user->update(['invited_at' => now()]);
            $user->notify(new EmployeeInvitation);
            $this->audit($user, $request, 'Invitación reenviada desde "olvidé mi contraseña"');
        } else {
            Password::broker()->sendResetLink(['email' => $email]);
            $this->audit($user, $request, 'Restablecimiento de contraseña solicitado');
        }

        return back()->with('success', self::SENT);
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $invalid = ValidationException::withMessages([
            'token' => 'El enlace para restablecer la contraseña no es válido o ya venció. Pedí uno nuevo.',
        ]);

        $user = User::where('email', str($data['email'])->lower())->first();

        // Mismo mensaje para cuenta inexistente, dada de baja o token incorrecto.
        if ($user === null || ! $user->is_active || $user->password === null) {
            throw $invalid;
        }
        // Primero el token: sin uno válido no se revela nada sobre la contraseña actual.
        if (! Password::broker()->tokenExists($user, $data['token'])) {
            throw $invalid;
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'La nueva contraseña debe ser distinta de la actual.']);
        }

        $status = Password::broker()->reset(
            ['email' => $user->email, 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token']],
            function (User $user, string $password) {
                // Al cambiar el hash, AuthenticateSession cierra todas las demás sesiones abiertas de esta cuenta.
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw $invalid;
        }

        $this->audit($user, $request, 'Contraseña restablecida con enlace');

        return redirect()->route('login')->with('success', 'Contraseña actualizada. Ingresá con la nueva.');
    }

    private function audit(User $user, Request $request, string $description): void
    {
        activity('security')->performedOn($user)->causedBy($user)
            ->withProperties(['ip' => $request->ip()])->log($description);
    }
}
