<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Crea (o actualiza) el superadministrador. Se usa en el primer despliegue: el seeder NO crea usuarios en producción.
 * Idempotente por email: si ya existe, lo reactiva, le asigna el rol sysadmin y actualiza nombre y contraseña.
 */
class CreateSysadminCommand extends Command
{
    protected $signature = 'agency:create-sysadmin
        {--name= : Nombre completo}
        {--email= : Email (identifica al usuario; si existe, se actualiza)}
        {--password= : Contraseña (si se omite, se pide de forma oculta)}';

    protected $description = 'Crea o actualiza (por email) un usuario con rol sysadmin. Pensado para el primer despliegue.';

    public function handle(): int
    {
        $interactive = $this->input->isInteractive();

        $name = $this->option('name');
        $email = $this->option('email');
        $plain = $this->option('password');

        if (! $interactive && (blank($name) || blank($email) || blank($plain))) {
            $this->error('Sin modo interactivo hay que indicar --name, --email y --password.');

            return self::INVALID;
        }

        $name = blank($name) ? text(label: 'Nombre completo', required: true) : (string) $name;
        $email = blank($email) ? text(label: 'Email', required: true) : (string) $email;
        $email = mb_strtolower(trim($email));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:255']],
            [],
            ['name' => 'nombre', 'email' => 'email'],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        // Contraseña: opción de línea de comandos o prompt oculto con confirmación (reintenta si no cumple la política).
        if (blank($plain)) {
            $plain = $this->askPasswordInteractively();
            if ($plain === null) {
                return self::INVALID;
            }
        } else {
            $errors = $this->passwordErrors((string) $plain);
            if ($errors !== []) {
                foreach ($errors as $message) {
                    $this->error($message);
                }

                return self::INVALID;
            }
        }

        $created = false;
        $user = DB::transaction(function () use ($name, $email, $plain, &$created): User {
            $this->ensureSysadminRoleExists();

            // withTrashed: un usuario dado de baja con ese email se restaura en lugar de chocar con el índice único.
            $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();
            $created = $user === null;
            $user ??= new User(['email' => $email]);

            if ($user->trashed()) {
                $user->restore();
            }

            $user->fill(['name' => $name, 'password' => $plain, 'is_active' => true]);
            $user->email_verified_at ??= now();
            $user->save();

            $user->syncRoles(['sysadmin']);

            return $user;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Auditoría (sin datos secretos).
        activity('security')->performedOn($user)->log($created ? 'Sysadmin creado por consola' : 'Sysadmin actualizado por consola');

        $this->info($created
            ? "Sysadmin creado: {$user->email}."
            : "El usuario {$user->email} ya existía: se lo reactivó, se actualizaron nombre y contraseña y quedó con rol sysadmin.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function passwordErrors(string $plain): array
    {
        $validator = Validator::make(
            ['password' => $plain],
            ['password' => ['required', 'string', Password::defaults()]],
            [],
            ['password' => 'contraseña'],
        );

        return $validator->fails() ? $validator->errors()->all() : [];
    }

    private function askPasswordInteractively(): ?string
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $plain = password(label: 'Contraseña (no se muestra)', required: true);
            $errors = $this->passwordErrors($plain);

            if ($errors !== []) {
                foreach ($errors as $message) {
                    $this->error($message);
                }

                continue;
            }

            if ($plain !== password(label: 'Repetí la contraseña', required: true)) {
                $this->error('Las contraseñas no coinciden.');

                continue;
            }

            return $plain;
        }

        $this->error('Demasiados intentos inválidos.');

        return null;
    }

    /** Si todavía no corrió el seeder de roles (primer despliegue), lo corre: es idempotente. */
    private function ensureSysadminRoleExists(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! Role::where('name', 'sysadmin')->where('guard_name', 'web')->exists()) {
            $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
