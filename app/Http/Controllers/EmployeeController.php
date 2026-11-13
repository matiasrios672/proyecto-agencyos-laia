<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\EmployeeInvitation;
use App\Notifications\TwoFactorWasReset;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Alta / baja de empleados (permiso employees.manage). */
class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();

        $employees = User::with('roles')->withCount('clients')
            ->role($actor->isSysadmin() ? ['sysadmin', 'dueno', 'empleado'] : ['dueno', 'empleado'])
            ->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'job_title' => $u->job_title,
                'role' => $u->roleName(),
                'role_label' => config('laia.role_labels.'.$u->roleName()),
                'state' => $u->accountState(),
                'two_factor' => $u->hasTwoFactorEnabled(),
                'clients_count' => $u->clients_count,
                'invited_at' => $u->invited_at?->toIso8601String(),
                'editable' => $this->canManage($actor, $u),
            ]);

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'assignableRoles' => collect($this->assignableRoles($actor))
                ->mapWithKeys(fn ($r) => [$r => config('laia.role_labels.'.$r)]),
            'invitationHours' => EmployeeInvitation::VALID_HOURS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'job_title' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'job_title' => $data['job_title'] ?? null,
                'password' => null,
                'is_active' => true,
                'invited_at' => now(),
            ]);
            $user->assignRole($data['role']);

            return $user;
        });

        $user->notify(new EmployeeInvitation);

        activity('employees')->performedOn($user)->causedBy($request->user())
            ->withProperties(['role' => $data['role']])->log('Empleado dado de alta');

        return back()->with('success', "Invitación enviada a {$user->email}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request->user(), $user), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
        ]);

        $user->update(['name' => $data['name'], 'job_title' => $data['job_title'] ?? null]);

        if ($user->roleName() !== $data['role']) {
            $user->syncRoles([$data['role']]);
            activity('employees')->performedOn($user)->causedBy($request->user())
                ->withProperties(['role' => $data['role']])->log('Rol de empleado modificado');
        }

        return back()->with('success', 'Empleado actualizado.');
    }

    public function resendInvitation(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request->user(), $user), 403);
        abort_if($user->password !== null, 422, 'Esta persona ya configuró su contraseña.');

        $user->update(['invited_at' => now()]);
        $user->notify(new EmployeeInvitation);

        return back()->with('success', "Invitación reenviada a {$user->email}.");
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request->user(), $user), 403);
        abort_if($user->is($request->user()), 422, 'No podés darte de baja a vos mismo.');

        $user->update(['is_active' => ! $user->is_active, 'remember_token' => null]);

        activity('employees')->performedOn($user)->causedBy($request->user())
            ->log($user->is_active ? 'Empleado reactivado' : 'Empleado dado de baja');

        return back()->with('success', $user->is_active ? 'Empleado reactivado.' : 'Empleado dado de baja.');
    }

    /** Dispositivo perdido: quita el 2FA de la persona para que pueda volver a activarlo. Se audita y se le avisa. */
    public function resetTwoFactor(Request $request, User $user, TwoFactor $twoFactor): RedirectResponse
    {
        abort_unless($this->canManage($request->user(), $user), 403);
        abort_unless($user->hasTwoFactorEnabled() || $user->hasPendingTwoFactor(), 422, 'Esta persona no tiene verificación en dos pasos activa.');

        $twoFactor->disable($user);

        activity('security')->performedOn($user)->causedBy($request->user())
            ->withProperties(['ip' => $request->ip()])->log('Verificación en dos pasos restablecida por un administrador');
        $user->notify(new TwoFactorWasReset($request->user()->name));

        return back()->with('success', "Se restableció la verificación en dos pasos de {$user->name}.");
    }

    /** Dirección gestiona empleados; solo el sysadmin crea / edita a otros dueños. */
    private function canManage(User $actor, User $target): bool
    {
        if ($target->isSysadmin()) {
            return $actor->isSysadmin() && ! $actor->is($target);
        }
        if ($target->hasRole('dueno')) {
            return $actor->isSysadmin();
        }

        return true;
    }

    /** @return list<string> */
    private function assignableRoles(User $actor): array
    {
        return $actor->isSysadmin() ? ['empleado', 'dueno'] : ['empleado'];
    }
}
