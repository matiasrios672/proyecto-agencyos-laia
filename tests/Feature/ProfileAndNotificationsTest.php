<?php

use App\Models\Client;
use App\Notifications\ClientAssigned;

describe('perfil', function () {
    it('muestra el perfil propio', function () {
        $user = userWithRole('empleado', ['name' => 'Camila Sosa']);

        $this->actingAs($user)->get('/perfil')->assertOk()->assertSee('Camila Sosa');
    });

    it('actualiza nombre y puesto, pero no email ni rol', function () {
        $user = userWithRole('empleado');

        $this->actingAs($user)->put('/perfil', [
            'name' => 'Nuevo Nombre', 'job_title' => 'CM Senior', 'email' => 'otro@laia.test', 'role' => 'sysadmin',
        ])->assertRedirect();

        $fresh = $user->fresh();
        expect($fresh->name)->toBe('Nuevo Nombre')
            ->and($fresh->job_title)->toBe('CM Senior')
            ->and($fresh->email)->toBe($user->email)
            ->and($fresh->hasRole('empleado'))->toBeTrue();
    });

    it('cambia la contraseña con la actual correcta', function () {
        $user = userWithRole('empleado', ['password' => 'Clave-actual-123']);

        $this->actingAs($user)->put('/perfil/contrasena', [
            'current_password' => 'Clave-actual-123',
            'password' => 'Clave-nueva-2026', 'password_confirmation' => 'Clave-nueva-2026',
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect(Hash::check('Clave-nueva-2026', $user->fresh()->password))->toBeTrue();
    });

    it('rechaza la contraseña actual incorrecta, débil o igual a la anterior', function () {
        $user = userWithRole('empleado', ['password' => 'Clave-actual-123']);

        $this->actingAs($user)->put('/perfil/contrasena', [
            'current_password' => 'incorrecta', 'password' => 'Clave-nueva-2026', 'password_confirmation' => 'Clave-nueva-2026',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put('/perfil/contrasena', [
            'current_password' => 'Clave-actual-123', 'password' => 'corta', 'password_confirmation' => 'corta',
        ])->assertSessionHasErrors('password');

        $this->actingAs($user)->put('/perfil/contrasena', [
            'current_password' => 'Clave-actual-123', 'password' => 'Clave-actual-123', 'password_confirmation' => 'Clave-actual-123',
        ])->assertSessionHasErrors('password');

        expect(Hash::check('Clave-actual-123', $user->fresh()->password))->toBeTrue();
    });

    it('exige sesión', function () {
        $this->get('/perfil')->assertRedirect('/login');
    });
});

describe('notificaciones', function () {
    it('avisa solo a quien fue asignado ahora, no a quien asigna ni a quien ya estaba', function () {
        $duena = userWithRole('dueno');
        $camila = userWithRole('empleado');
        $tomas = userWithRole('empleado');
        $client = Client::factory()->create();
        $client->users()->attach($camila);

        $this->actingAs($duena)->put("/clientes/{$client->id}/equipo", ['user_ids' => [$camila->id, $tomas->id]]);

        expect($tomas->notifications()->count())->toBe(1)
            ->and($tomas->notifications->first()->type)->toBe(ClientAssigned::class)
            ->and($camila->notifications()->count())->toBe(0)
            ->and($duena->notifications()->count())->toBe(0);
    });

    it('lista las propias con contador de no leídas y marca como leídas', function () {
        $duena = userWithRole('dueno');
        $camila = userWithRole('empleado');
        $client = Client::factory()->create(['business_name' => 'Panadería La Espiga']);

        $this->actingAs($duena)->put("/clientes/{$client->id}/equipo", ['user_ids' => [$camila->id]]);

        $response = $this->actingAs($camila)->getJson('/notificaciones')->assertOk();
        $response->assertJsonPath('unread', 1)->assertJsonPath('items.0.url', "/clientes/{$client->id}");

        $id = $response->json('items.0.id');
        $this->actingAs($camila)->postJson("/notificaciones/{$id}/leer")->assertOk();
        $this->actingAs($camila)->getJson('/notificaciones')->assertJsonPath('unread', 0);
    });

    it('no permite leer ni marcar notificaciones ajenas', function () {
        $duena = userWithRole('dueno');
        $camila = userWithRole('empleado');
        $tomas = userWithRole('empleado');
        $client = Client::factory()->create();

        $this->actingAs($duena)->put("/clientes/{$client->id}/equipo", ['user_ids' => [$camila->id]]);
        $id = $camila->notifications()->first()->id;

        $this->actingAs($tomas)->getJson('/notificaciones')->assertJsonPath('unread', 0)->assertJsonCount(0, 'items');
        $this->actingAs($tomas)->postJson("/notificaciones/{$id}/leer")->assertNotFound();
        expect($camila->unreadNotifications()->count())->toBe(1);
    });

    it('marca todas como leídas', function () {
        $duena = userWithRole('dueno');
        $camila = userWithRole('empleado');

        foreach (Client::factory()->count(3)->create() as $c) {
            $this->actingAs($duena)->put("/clientes/{$c->id}/equipo", ['user_ids' => [$camila->id]]);
        }
        expect($camila->unreadNotifications()->count())->toBe(3);

        $this->actingAs($camila)->postJson('/notificaciones/leer')->assertOk();

        expect($camila->unreadNotifications()->count())->toBe(0);
    });

    it('entrega el contador de no leídas en cada página (sin esperar a una consulta aparte)', function () {
        $duena = userWithRole('dueno');
        $camila = userWithRole('empleado');
        $client = Client::factory()->create();

        $this->actingAs($camila)->get('/')->assertInertia(fn ($page) => $page->where('unread_notifications', 0));

        $this->actingAs($duena)->put("/clientes/{$client->id}/equipo", ['user_ids' => [$camila->id]]);
        $this->actingAs($camila)->get('/')->assertInertia(fn ($page) => $page->where('unread_notifications', 1));

        $this->actingAs($camila)->postJson('/notificaciones/leer');
        $this->actingAs($camila)->get('/')->assertInertia(fn ($page) => $page->where('unread_notifications', 0));
    });
});

describe('dashboard', function () {
    it('el empleado solo cuenta sus clientes asignados', function () {
        $camila = userWithRole('empleado');
        $propio = Client::factory()->create(['account_status' => 'deuda', 'business_name' => 'Cliente Propio', 'trade_name' => null]);
        Client::factory()->create(['account_status' => 'deuda', 'business_name' => 'Cliente Ajeno', 'trade_name' => null]);
        $propio->users()->attach($camila);

        $this->actingAs($camila)->get('/')->assertOk()
            ->assertSee('Cliente Propio')->assertDontSee('Cliente Ajeno');
    });

    it('dirección ve todos los clientes que requieren atención', function () {
        Client::factory()->create(['account_status' => 'deuda', 'business_name' => 'Cliente Moroso', 'trade_name' => null]);

        $this->actingAs(userWithRole('dueno'))->get('/')->assertOk()->assertSee('Cliente Moroso');
    });
});
