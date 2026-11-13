<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El empleado recibe un correo con una URL firmada para definir su contraseña.
 */
class InvitationController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'El enlace de invitación venció o no es válido.');

        return Inertia::render('Auth/AcceptInvitation', [
            'user' => ['name' => $user->name, 'email' => $user->email],
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'El enlace de invitación venció o no es válido.');

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/')->with('success', '¡Contraseña configurada! Bienvenido a Agency OS.');
    }
}
