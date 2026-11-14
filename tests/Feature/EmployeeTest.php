<?php

use App\Models\User;
use App\Notifications\EmployeeInvitation;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->admin = userWithRole('sysadmin');
    $this->camila = userWithRole('empleado');
});

it('el empleado no accede al módulo de equipo', function () {
    $this->actingAs($this->camila)->get('/equipo')->assertForbidden();
    $this->actingAs($this->camila)->post('/equipo', [])->assertForbidden();
});

it('dirección ve el equipo sin mostrar al sysadmin', function () {
    $this->actingAs($this->duena)->get('/equipo')->assertOk()
        ->assertSee($this->camila->email)->assertDontSee($this->admin->email);
});

describe('alta', function () {
    it('crea el empleado sin contraseña y envía la invitación', function () {
        Notification::fake();

        $this->actingAs($this->duena)->post('/equipo', [
            'name' => 'Julián Peralta', 'email' => 'julian@laia.test', 'job_title' => 'Desarrollador', 'role' => 'empleado',
        ])->assertRedirect();

        $user = User::where('email', 'julian@laia.test')->first();
        expect($user->password)->toBeNull()
            ->and($user->hasRole('empleado'))->toBeTrue()
            ->and($user->invited_at)->not->toBeNull()
            ->and($user->accountState())->toBe('pending');
        Notification::assertSentTo($user, EmployeeInvitation::class);
    });

    it('el correo contiene una URL firmada que permite configurar la contraseña', function () {
        $user = User::factory()->create(['password' => null]);
        $user->assignRole('empleado');

        $mail = (new EmployeeInvitation)->toMail($user);
        preg_match('/href="([^"]*invitacion[^"]*)"/', $mail->viewData['bodyMarkdown'], $m);
        $url = html_entity_decode($m[1] ?? '');

        expect($url)->toContain('signature=')->toContain("/invitacion/{$user->id}");
        $this->get($url)->assertOk();
    });

    it('dirección no puede crear otros dueños; el sysadmin sí', function () {
        Notification::fake();
        $payload = ['name' => 'Otra Dueña', 'email' => 'otra@laia.test', 'role' => 'dueno'];

        $this->actingAs($this->duena)->post('/equipo', $payload)->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->post('/equipo', $payload)->assertRedirect();

        expect(User::where('email', 'otra@laia.test')->first()->hasRole('dueno'))->toBeTrue();
    });

    it('nadie puede crear sysadmins desde la UI', function () {
        $this->actingAs($this->admin)->post('/equipo', ['name' => 'X', 'email' => 'x@laia.test', 'role' => 'sysadmin'])
            ->assertSessionHasErrors('role');
    });

    it('rechaza emails repetidos', function () {
        $this->actingAs($this->duena)->post('/equipo', [
            'name' => 'Dup', 'email' => $this->camila->email, 'role' => 'empleado',
        ])->assertSessionHasErrors('email');
    });

    it('reenvía la invitación solo a quien no configuró su contraseña', function () {
        Notification::fake();
        $pendiente = User::factory()->create(['password' => null]);
        $pendiente->assignRole('empleado');

        $this->actingAs($this->duena)->post("/equipo/{$pendiente->id}/invitacion")->assertRedirect();
        Notification::assertSentTo($pendiente, EmployeeInvitation::class);

        $this->actingAs($this->duena)->post("/equipo/{$this->camila->id}/invitacion")->assertStatus(422);
    });
});

describe('baja y permisos de gestión', function () {
    it('da de baja y reactiva a un empleado', function () {
        $this->actingAs($this->duena)->post("/equipo/{$this->camila->id}/estado")->assertRedirect();
        expect($this->camila->fresh()->is_active)->toBeFalse();

        $this->actingAs($this->duena)->post("/equipo/{$this->camila->id}/estado")->assertRedirect();
        expect($this->camila->fresh()->is_active)->toBeTrue();
    });

    it('un empleado dado de baja no puede iniciar sesión', function () {
        $this->camila->update(['is_active' => false, 'password' => 'Clave-segura-123']);

        $this->post('/login', ['email' => $this->camila->email, 'password' => 'Clave-segura-123'])
            ->assertSessionHasErrors('email');
    });

    it('cierra la sesión activa de quien fue dado de baja', function () {
        $this->actingAs($this->camila);
        $this->camila->update(['is_active' => false]);

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    });

    it('no permite darse de baja a uno mismo', function () {
        $this->actingAs($this->duena)->post("/equipo/{$this->duena->id}/estado")->assertStatus(403);
    });

    it('dirección no puede tocar a otra dirección ni al sysadmin', function () {
        $otra = userWithRole('dueno');

        $this->actingAs($this->duena)->post("/equipo/{$otra->id}/estado")->assertForbidden();
        $this->actingAs($this->duena)->post("/equipo/{$this->admin->id}/estado")->assertForbidden();
        $this->actingAs($this->duena)->put("/equipo/{$otra->id}", ['name' => 'Hack', 'role' => 'empleado'])->assertForbidden();
    });

    it('cambia el rol de un empleado y lo audita', function () {
        $this->actingAs($this->admin)->put("/equipo/{$this->camila->id}", [
            'name' => 'Camila Sosa', 'job_title' => 'CM Senior', 'role' => 'dueno',
        ])->assertRedirect();

        expect($this->camila->fresh()->hasRole('dueno'))->toBeTrue();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'employees', 'description' => 'Rol de empleado modificado']);
    });
});
