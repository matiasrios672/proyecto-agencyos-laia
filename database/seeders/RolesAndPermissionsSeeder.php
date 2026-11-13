<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (config('laia.permissions') as $group) {
            foreach (array_keys($group) as $name) {
                Permission::findOrCreate($name, 'web');
            }
        }

        foreach (config('laia.roles') as $role) {
            Role::findOrCreate($role, 'web');
        }

        // Solo se aplican los defaults al crear; la matriz visual puede modificarlos luego.
        foreach (config('laia.role_defaults') as $role => $permissions) {
            $r = Role::findByName($role, 'web');
            if ($r->permissions()->count() === 0) {
                $r->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
