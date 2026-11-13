<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Usuarios de demostración de Laia Estudio Creativo Digital (solo entornos no productivos).
        if (app()->environment('production')) {
            return;
        }

        $people = [
            ['Matías Ríos', 'admin@laia.test', 'Sysadmin', 'sysadmin'],
            ['Lucía Ferrero', 'direccion@laia.test', 'Directora creativa', 'dueno'],
            ['Camila Sosa', 'camila@laia.test', 'Community Manager', 'empleado'],
            ['Tomás Aguirre', 'tomas@laia.test', 'Diseñador gráfico', 'empleado'],
            ['Julián Peralta', 'julian@laia.test', 'Desarrollador web', 'empleado'],
        ];

        foreach ($people as [$name, $email, $title, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'job_title' => $title, 'password' => 'Laia-demo-2026', 'email_verified_at' => now(), 'is_active' => true],
            );
            $user->syncRoles([$role]);
        }

        $this->call(ClientSeeder::class);
        $this->call(SalesSeeder::class);
        $this->call(FinanceSeeder::class);
        $this->call(ContentSeeder::class);
        $this->call(KanbanSeeder::class);
        $this->call(LaiaTimeSeeder::class);
        $this->call(MessagingSeeder::class);
    }
}
