<?php

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Support\Facades\Crypt;

$adminRoutes = ['/admin/configuracion', '/admin/permisos', '/admin/sistema'];

it('permite al sysadmin entrar a todo el panel', function () use ($adminRoutes) {
    $admin = userWithRole('sysadmin');

    foreach ($adminRoutes as $route) {
        $this->actingAs($admin)->get($route)->assertOk();
    }
});

it('prohíbe el panel a dueño y empleado', function (string $role) use ($adminRoutes) {
    $user = userWithRole($role);

    foreach ($adminRoutes as $route) {
        $this->actingAs($user)->get($route)->assertForbidden();
    }

    $this->actingAs($user)->put('/admin/configuracion', ['values' => ['smtp.host' => 'x']])->assertForbidden();
    $this->actingAs($user)->post('/admin/permisos/rol', [
        'role' => 'empleado', 'permission' => 'vault.manage', 'enabled' => true,
    ])->assertForbidden();
})->with(['dueno', 'empleado']);

describe('configuración del sistema', function () {
    it('guarda ajustes y los lee con caché', function () {
        $this->actingAs(userWithRole('sysadmin'))
            ->put('/admin/configuracion', ['values' => ['bank.alias' => 'laia.estudio']])
            ->assertRedirect();

        expect(Settings::get('bank.alias'))->toBe('laia.estudio');
    });

    it('cifra los secretos en la base de datos', function () {
        Settings::set('laiatime.api_key', 'sk_live_secreta');

        $row = Setting::where('key', 'laiatime.api_key')->first();

        expect($row->value)->not->toBe('sk_live_secreta')
            ->and(Crypt::decryptString($row->value))->toBe('sk_live_secreta')
            ->and(Settings::get('laiatime.api_key'))->toBe('sk_live_secreta');
    });

    it('nunca envía los secretos al navegador', function () {
        Settings::set('smtp.password', 'super-secreto');

        $response = $this->actingAs(userWithRole('sysadmin'))->get('/admin/configuracion');

        expect($response->getContent())->not->toContain('super-secreto');
    });

    it('un secreto vacío no pisa el valor existente', function () {
        Settings::set('smtp.password', 'super-secreto');

        $this->actingAs(userWithRole('sysadmin'))
            ->put('/admin/configuracion', ['values' => ['smtp.password' => '']]);

        expect(Settings::get('smtp.password'))->toBe('super-secreto');
    });

    it('ignora claves desconocidas', function () {
        $this->actingAs(userWithRole('sysadmin'))
            ->put('/admin/configuracion', ['values' => ['inventada.clave' => 'x']])
            ->assertRedirect();

        expect(Setting::where('key', 'inventada.clave')->exists())->toBeFalse();
    });

    it('usa el valor por defecto si no hay nada guardado', function () {
        expect(Settings::get('dunning.grace_days'))->toBe('10');
    });
});

describe('matriz de permisos', function () {
    it('activa y desactiva un permiso para un rol', function () {
        $admin = userWithRole('sysadmin');
        $empleado = userWithRole('empleado');

        expect($empleado->can('vault.manage'))->toBeFalse();

        $this->actingAs($admin)->postJson('/admin/permisos/rol', [
            'role' => 'empleado', 'permission' => 'vault.manage', 'enabled' => true,
        ])->assertOk();

        expect($empleado->fresh()->can('vault.manage'))->toBeTrue();

        $this->actingAs($admin)->postJson('/admin/permisos/rol', [
            'role' => 'empleado', 'permission' => 'vault.manage', 'enabled' => false,
        ])->assertOk();

        expect($empleado->fresh()->can('vault.manage'))->toBeFalse();
    });

    it('otorga un permiso extra a un usuario puntual', function () {
        $admin = userWithRole('sysadmin');
        $camila = userWithRole('empleado');
        $tomas = userWithRole('empleado');

        $this->actingAs($admin)->postJson("/admin/permisos/usuario/{$camila->id}", [
            'permission' => 'finance.view', 'enabled' => true,
        ])->assertOk();

        expect($camila->fresh()->can('finance.view'))->toBeTrue()
            ->and($tomas->fresh()->can('finance.view'))->toBeFalse();
    });

    it('rechaza permisos y roles inexistentes', function () {
        $admin = userWithRole('sysadmin');

        $this->actingAs($admin)->postJson('/admin/permisos/rol', [
            'role' => 'empleado', 'permission' => 'hackear.todo', 'enabled' => true,
        ])->assertUnprocessable();

        $this->actingAs($admin)->postJson('/admin/permisos/rol', [
            'role' => 'sysadmin', 'permission' => 'vault.view', 'enabled' => false,
        ])->assertUnprocessable();
    });

    it('registra cada cambio en el log de actividad', function () {
        $this->actingAs(userWithRole('sysadmin'))->postJson('/admin/permisos/rol', [
            'role' => 'empleado', 'permission' => 'vault.manage', 'enabled' => true,
        ]);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'permissions', 'description' => 'Permiso modificado']);
    });
});

it('el sysadmin pasa cualquier gate sin permisos explícitos', function () {
    expect(userWithRole('sysadmin')->can('vault.view'))->toBeTrue();
});
