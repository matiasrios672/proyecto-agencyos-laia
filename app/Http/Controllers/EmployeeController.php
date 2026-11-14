<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\EmployeeInvitation;
use App\Notifications\PasswordChangedByAdmin;
use App\Notifications\TwoFactorWasReset;
use App\Support\BankAccount;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
                // Los datos bancarios solo viajan al navegador de quien puede editarlos.
                'bank' => $this->canManage($actor, $u) ? $this->bankProps($u) : null,
                'bank_loaded' => BankAccount::hasData($u),
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

    /**
     * Cambia la contraseña de alguien del equipo: genera una nueva (se muestra UNA vez) o fija una elegida.
     * La contraseña actual no se puede ver: se guarda con hash. Se avisa a la persona y queda en la auditoría
     * (sin la clave). Va por JSON para que la clave generada no pase por la sesión ni por el correo.
     */
    public function setPassword(Request $request, User $user): JsonResponse
    {
        abort_unless($this->canManage($request->user(), $user), 403);
        abort_if($user->is($request->user()), 422, 'Para cambiar tu propia contraseña usá «Mi perfil».');
        abort_unless($user->is_active, 422, 'Esta persona está dada de baja: reactivala antes de cambiarle la contraseña.');

        $data = $request->validate([
            'mode' => ['required', Rule::in(['generate', 'set'])],
            'password' => ['required_if:mode,set', 'nullable', 'string', 'confirmed', Password::defaults()],
        ]);

        $plain = $data['mode'] === 'generate' ? $this->generatePassword() : $data['password'];

        // remember_token en null cierra las sesiones "recordadas" de esa persona.
        $user->forceFill(['password' => $plain, 'remember_token' => null])->save();

        activity('security')->performedOn($user)->causedBy($request->user())
            ->withProperties(['mode' => $data['mode'], 'ip' => $request->ip()])->log('Contraseña cambiada por un administrador');
        $user->notify(new PasswordChangedByAdmin($request->user()->name));

        return response()
            ->json($data['mode'] === 'generate' ? ['password' => $plain] : ['ok' => true])
            ->header('Cache-Control', 'no-store, private');
    }

    /** 14 caracteres sin los que se confunden al dictarlos (0/O, 1/l/I); siempre con mayúscula, minúscula y número. */
    private function generatePassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
        $all = implode('', $sets);

        $chars = array_map(fn (string $set) => $set[random_int(0, strlen($set) - 1)], $sets);
        while (count($chars) < 14) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) { // mezcla (Fisher-Yates con aleatoriedad criptográfica)
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** Dirección gestiona empleados; solo el sysadmin crea / edita a otros dueños. */
    private function canManage(User $actor, User $target): bool
    {
        return $target->manageableBy($actor);
    }

    /** @return array<string, string> */
    public static function bankProps(User $user): array
    {
        return [
            'bank_holder' => (string) $user->bank_holder,
            'bank_tax_id' => (string) $user->bank_tax_id,
            'bank_name' => (string) $user->bank_name,
            'bank_cbu' => (string) $user->bank_cbu,
            'bank_alias' => (string) $user->bank_alias,
        ];
    }

    /** @return list<string> */
    private function assignableRoles(User $actor): array
    {
        return $actor->isSysadmin() ? ['empleado', 'dueno'] : ['empleado'];
    }
}
