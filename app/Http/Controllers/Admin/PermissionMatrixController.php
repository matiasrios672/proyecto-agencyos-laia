<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionMatrixController extends Controller
{
    public function index(): Response
    {
        $roles = Role::with('permissions')->whereIn('name', ['dueno', 'empleado'])->get()
            ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->all()]);

        $users = User::with(['roles', 'permissions'])->orderBy('name')->get()
            ->reject(fn (User $u) => $u->isSysadmin())
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->roleName(),
                // Solo permisos asignados directamente (overrides sobre el rol).
                'direct' => $u->permissions->pluck('name')->all(),
                'inherited' => $u->getPermissionsViaRoles()->pluck('name')->all(),
            ])->values();

        return Inertia::render('Admin/Permissions', [
            'catalog' => config('laia.permissions'),
            'roles' => $roles,
            'roleLabels' => config('laia.role_labels'),
            'users' => $users,
        ]);
    }

    public function toggleRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['dueno', 'empleado'])],
            'permission' => ['required', Rule::in($this->allPermissions())],
            'enabled' => ['required', 'boolean'],
        ]);

        $role = Role::findByName($data['role']);
        $data['enabled'] ? $role->givePermissionTo($data['permission']) : $role->revokePermissionTo($data['permission']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->audit($request, "role:{$data['role']}", $data);

        return response()->json(['ok' => true]);
    }

    public function toggleUser(Request $request, User $user): JsonResponse
    {
        abort_if($user->isSysadmin(), 422, 'El sysadmin siempre tiene acceso total.');

        $data = Validator::make($request->all(), [
            'permission' => ['required', Rule::in($this->allPermissions())],
            'enabled' => ['required', 'boolean'],
        ])->validate();

        $data['enabled'] ? $user->givePermissionTo($data['permission']) : $user->revokePermissionTo($data['permission']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->audit($request, "user:{$user->id}", $data);

        return response()->json(['ok' => true]);
    }

    /** @return list<string> */
    private function allPermissions(): array
    {
        return collect(config('laia.permissions'))->flatMap(fn ($g) => array_keys($g))->values()->all();
    }

    private function audit(Request $request, string $target, array $data): void
    {
        activity('permissions')->causedBy($request->user())
            ->withProperties(['target' => $target, ...$data])
            ->log('Permiso modificado');
    }
}
