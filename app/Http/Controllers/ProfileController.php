<?php

namespace App\Http\Controllers;

use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request, TwoFactor $twoFactor): Response
    {
        $user = $request->user();
        $pending = $user->hasPendingTwoFactor();

        return Inertia::render('Profile/Edit', [
            // El secreto solo viaja al navegador mientras dura el alta (para mostrar el QR); activo, nunca más.
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending' => $pending,
                'required' => TwoFactor::requiredFor($user),
                'recovery_remaining' => $twoFactor->remainingRecoveryCodes($user),
                'secret' => $pending ? $user->two_factor_secret : null,
                'otpauth' => $pending ? $twoFactor->otpauthUrl($user, $user->two_factor_secret) : null,
            ],
            // Códigos de recuperación recién generados: se muestran una sola vez (flash de la sesión).
            'recoveryCodes' => $request->session()->get('recovery_codes'),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'job_title' => $user->job_title,
                'role_label' => config('laia.role_labels.'.$user->roleName()),
                'member_since' => $user->created_at?->toIso8601String(),
                'clients_count' => $user->clients()->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'Perfil actualizado.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults(), Rule::notIn([$request->input('current_password')])],
        ], [
            'password.not_in' => 'La nueva contraseña debe ser distinta de la actual.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'La contraseña actual no es correcta.']);
        }

        $user->update(['password' => $data['password']]);

        // Invalida las demás sesiones abiertas con la contraseña anterior.
        Auth::logoutOtherDevices($data['password']);

        return back()->with('success', 'Contraseña actualizada.');
    }
}
