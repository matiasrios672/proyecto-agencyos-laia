<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('crea un sysadmin sin interacción con name, email y password', function () {
    $this->artisan('agency:create-sysadmin', [
        '--name' => 'Ana Admin',
        '--email' => 'Ana@Laia.Studio',
        '--password' => 'ClaveSegura2026',
        '--no-interaction' => true,
    ])->expectsOutputToContain('Sysadmin creado: ana@laia.studio')->assertSuccessful();

    $user = User::where('email', 'ana@laia.studio')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Ana Admin')
        ->and($user->hasRole('sysadmin'))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe('ClaveSegura2026')
        ->and(Hash::check('ClaveSegura2026', $user->password))->toBeTrue();
});

it('exige todas las opciones sin modo interactivo', function () {
    $this->artisan('agency:create-sysadmin', ['--email' => 'a@b.com', '--no-interaction' => true])
        ->expectsOutputToContain('--name, --email y --password')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('rechaza contraseñas que no cumplen la política', function (string $weak) {
    $this->artisan('agency:create-sysadmin', [
        '--name' => 'Ana', '--email' => 'ana@laia.studio', '--password' => $weak, '--no-interaction' => true,
    ])->assertFailed();

    expect(User::where('email', 'ana@laia.studio')->exists())->toBeFalse();
})->with(['corta' => 'abc12', 'sin números' => 'SoloLetrasLargas', 'sin letras' => '12345678901234']);

it('rechaza un email inválido', function () {
    $this->artisan('agency:create-sysadmin', [
        '--name' => 'Ana', '--email' => 'no-es-un-email', '--password' => 'ClaveSegura2026', '--no-interaction' => true,
    ])->assertFailed();

    expect(User::count())->toBe(0);
});

it('es idempotente por email: reactiva, actualiza y no duplica', function () {
    $existing = userWithRole('empleado', ['email' => 'ana@laia.studio', 'name' => 'Vieja', 'is_active' => false]);

    $this->artisan('agency:create-sysadmin', [
        '--name' => 'Ana Nueva', '--email' => 'ana@laia.studio', '--password' => 'OtraClave2026x', '--no-interaction' => true,
    ])->expectsOutputToContain('ya existía')->assertSuccessful();

    $this->artisan('agency:create-sysadmin', [
        '--name' => 'Ana Nueva', '--email' => 'ana@laia.studio', '--password' => 'OtraClave2026x', '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::where('email', 'ana@laia.studio')->count())->toBe(1);
    $user = $existing->fresh();
    expect($user->name)->toBe('Ana Nueva')
        ->and($user->is_active)->toBeTrue()
        ->and($user->roles->pluck('name')->all())->toBe(['sysadmin'])
        ->and(Hash::check('OtraClave2026x', $user->password))->toBeTrue();
});

it('restaura a un usuario dado de baja con ese email', function () {
    $user = userWithRole('empleado', ['email' => 'ana@laia.studio']);
    $user->delete();

    $this->artisan('agency:create-sysadmin', [
        '--name' => 'Ana', '--email' => 'ana@laia.studio', '--password' => 'ClaveSegura2026', '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::where('email', 'ana@laia.studio')->count())->toBe(1)
        ->and($user->fresh()->trashed())->toBeFalse()
        ->and($user->fresh()->hasRole('sysadmin'))->toBeTrue();
});

it('crea los roles si todavía no existen (primer despliegue)', function () {
    Role::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('agency:create-sysadmin', [
        '--name' => 'Ana', '--email' => 'ana@laia.studio', '--password' => 'ClaveSegura2026', '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::where('email', 'ana@laia.studio')->first()->hasRole('sysadmin'))->toBeTrue();
});

it('en modo interactivo pide los datos y la contraseña oculta con confirmación', function () {
    $this->artisan('agency:create-sysadmin')
        ->expectsQuestion('Nombre completo', 'Beto Boss')
        ->expectsQuestion('Email', 'beto@laia.studio')
        ->expectsQuestion('Contraseña (no se muestra)', 'ClaveSegura2026')
        ->expectsQuestion('Repetí la contraseña', 'ClaveSegura2026')
        ->assertSuccessful();

    expect(User::where('email', 'beto@laia.studio')->first()?->hasRole('sysadmin'))->toBeTrue();
});

it('en modo interactivo reintenta si la contraseña es débil o no coincide', function () {
    $this->artisan('agency:create-sysadmin', ['--name' => 'Beto', '--email' => 'beto@laia.studio'])
        ->expectsQuestion('Contraseña (no se muestra)', 'corta1')
        ->expectsQuestion('Contraseña (no se muestra)', 'ClaveSegura2026')
        ->expectsQuestion('Repetí la contraseña', 'OtraDistinta2026')
        ->expectsQuestion('Contraseña (no se muestra)', 'ClaveSegura2026')
        ->expectsQuestion('Repetí la contraseña', 'ClaveSegura2026')
        ->assertSuccessful();

    expect(User::where('email', 'beto@laia.studio')->exists())->toBeTrue();
});

it('registra la auditoría sin guardar la contraseña', function () {
    $this->artisan('agency:create-sysadmin', [
        '--name' => 'Ana', '--email' => 'ana@laia.studio', '--password' => 'ClaveSegura2026', '--no-interaction' => true,
    ])->assertSuccessful();

    $log = Activity::where('log_name', 'security')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and(json_encode($log->properties))->not->toContain('ClaveSegura2026');
});
