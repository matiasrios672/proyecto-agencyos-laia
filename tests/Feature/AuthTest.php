<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

it('redirige a login a los invitados', function () {
    $this->get('/')->assertRedirect('/login');
});

it('permite iniciar sesión con credenciales válidas', function () {
    $user = userWithRole('empleado', ['password' => 'Clave-segura-123']);

    $this->post('/login', ['email' => $user->email, 'password' => 'Clave-segura-123'])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('rechaza credenciales inválidas', function () {
    $user = userWithRole('empleado', ['password' => 'Clave-segura-123']);

    $this->post('/login', ['email' => $user->email, 'password' => 'incorrecta'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('no deja entrar a usuarios dados de baja (inactivos)', function () {
    $user = userWithRole('empleado', ['password' => 'Clave-segura-123', 'is_active' => false]);

    $this->post('/login', ['email' => $user->email, 'password' => 'Clave-segura-123'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('bloquea tras demasiados intentos fallidos', function () {
    $user = userWithRole('empleado', ['password' => 'Clave-segura-123']);

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'mal']);
    }

    // Incluso con la clave correcta, queda bloqueado.
    $this->post('/login', ['email' => $user->email, 'password' => 'Clave-segura-123'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('cierra sesión', function () {
    $this->actingAs(userWithRole('empleado'))->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});

describe('invitación de empleados', function () {
    it('rechaza enlaces sin firma válida', function () {
        $user = User::factory()->create(['password' => null]);

        $this->get("/invitacion/{$user->id}")->assertForbidden();
    });

    it('permite configurar la contraseña con URL firmada', function () {
        $user = User::factory()->create(['password' => null, 'email_verified_at' => null]);
        $user->assignRole('empleado');
        $url = URL::temporarySignedRoute('invitation.show', now()->addDay(), ['user' => $user->id]);

        $this->get($url)->assertOk();

        $this->post(
            URL::temporarySignedRoute('invitation.store', now()->addDay(), ['user' => $user->id]),
            ['password' => 'Nueva-clave-2026', 'password_confirmation' => 'Nueva-clave-2026'],
        )->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        expect($user->fresh()->password)->not->toBeNull()
            ->and($user->fresh()->email_verified_at)->not->toBeNull();
    });

    it('rechaza contraseñas débiles', function () {
        $user = User::factory()->create(['password' => null]);

        $this->post(
            URL::temporarySignedRoute('invitation.store', now()->addDay(), ['user' => $user->id]),
            ['password' => 'corta', 'password_confirmation' => 'corta'],
        )->assertSessionHasErrors('password');
    });

    it('rechaza enlaces vencidos', function () {
        $user = User::factory()->create(['password' => null]);
        $url = URL::temporarySignedRoute('invitation.show', now()->subMinute(), ['user' => $user->id]);

        $this->get($url)->assertForbidden();
    });
});
