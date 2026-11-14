<?php

use App\Models\User;
use App\Notifications\EmployeeInvitation;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->user = userWithRole('empleado', ['password' => 'Clave-actual-123', 'email' => 'camila@laia.test']);
});

/** Pide el enlace y devuelve el token que viajó en el correo. */
function requestResetToken(User $user): string
{
    $token = null;
    test()->post('/olvide-mi-contrasena', ['email' => $user->email])->assertRedirect();
    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    return $token;
}

function resetPayload(string $token, string $email, string $password = 'Nueva-clave-2026'): array
{
    return ['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password];
}

describe('pedido del enlace', function () {
    it('muestra las pantallas a los invitados y redirige a quien ya inició sesión', function () {
        $this->get('/olvide-mi-contrasena')->assertOk();
        $this->get('/restablecer-contrasena/abc123?email=camila@laia.test')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Auth/ResetPassword', false)->where('token', 'abc123')->where('email', 'camila@laia.test'));

        $this->actingAs($this->user)->get('/olvide-mi-contrasena')->assertRedirect('/');
    });

    it('envía el correo con el enlace a una cuenta activa y lo audita sin datos sensibles', function () {
        $token = requestResetToken($this->user);

        expect($token)->not->toBeEmpty();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'Restablecimiento de contraseña solicitado', 'causer_id' => $this->user->id]);
        expect(\DB::table('activity_log')->where('log_name', 'security')->get()->toJson())->not->toContain($token);
    });

    it('responde exactamente igual exista o no la cuenta (no se pueden enumerar emails)', function () {
        $inactiva = userWithRole('empleado', ['password' => 'Clave-actual-123', 'is_active' => false, 'email' => 'baja@laia.test']);

        $respuestas = collect([$this->user->email, 'no-existe@laia.test', $inactiva->email])->map(function (string $email) {
            $r = $this->post('/olvide-mi-contrasena', ['email' => $email]);
            $r->assertRedirect()->assertSessionHasNoErrors();

            return session('success');
        });

        expect($respuestas->unique())->toHaveCount(1);
        Notification::assertSentToTimes($this->user, ResetPasswordLink::class, 1);
        Notification::assertNotSentTo($inactiva, ResetPasswordLink::class);
        Notification::assertCount(1);
    });

    it('no envía nada a un usuario eliminado', function () {
        $this->user->delete();

        $this->post('/olvide-mi-contrasena', ['email' => 'camila@laia.test'])->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    });

    it('al que todavía no aceptó la invitación le reenvía la invitación firmada, no un reset', function () {
        $pendiente = User::factory()->create(['password' => null, 'email' => 'nuevo@laia.test']);
        $pendiente->assignRole('empleado');

        $this->post('/olvide-mi-contrasena', ['email' => 'nuevo@laia.test'])->assertSessionHasNoErrors();

        Notification::assertSentTo($pendiente, EmployeeInvitation::class);
        Notification::assertNotSentTo($pendiente, ResetPasswordLink::class);
        expect($pendiente->fresh()->invited_at)->not->toBeNull();
    });

    it('valida el email', function () {
        $this->post('/olvide-mi-contrasena', ['email' => 'esto-no-es-un-email'])->assertSessionHasErrors('email');
        $this->post('/olvide-mi-contrasena', [])->assertSessionHasErrors('email');
    });

    it('limita los pedidos por email + IP (también para cuentas inexistentes)', function () {
        foreach (range(1, 5) as $_) {
            $this->post('/olvide-mi-contrasena', ['email' => 'nadie@laia.test'])->assertSessionHasNoErrors();
        }

        $this->post('/olvide-mi-contrasena', ['email' => 'nadie@laia.test'])->assertSessionHasErrors('email');
        // Otro email desde la misma IP tiene su propio contador.
        $this->post('/olvide-mi-contrasena', ['email' => 'otro@laia.test'])->assertSessionHasNoErrors();
        // El email se normaliza: mayúsculas no esquivan el límite.
        $this->post('/olvide-mi-contrasena', ['email' => 'NADIE@laia.test'])->assertSessionHasErrors('email');
    });

    it('el correo tiene el enlace personal, el asunto en español y el plazo', function () {
        $mail = (new ResetPasswordLink('tok-123'))->toMail($this->user);

        $html = html_entity_decode($mail->viewData['bodyMarkdown']);

        expect($mail->subject)->toContain('contraseña')
            ->and($html)->toContain('/restablecer-contrasena/tok-123')->toContain('email=camila%40laia.test')
            ->and($html)->toContain('Recibimos un pedido')->toContain('60 minutos')->toContain('una sola vez');
    });
});

describe('restablecimiento', function () {
    it('flujo completo: con el token cambia la contraseña y se puede ingresar con la nueva', function () {
        $token = requestResetToken($this->user);
        $viejoHash = $this->user->fresh()->password;
        $viejoRemember = $this->user->fresh()->remember_token;

        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email))
            ->assertRedirect('/login')->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $this->user->fresh();
        expect(Hash::check('Nueva-clave-2026', $fresh->password))->toBeTrue()
            ->and($fresh->password)->not->toBe($viejoHash)
            ->and($fresh->remember_token)->not->toBe($viejoRemember);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'Contraseña restablecida con enlace']);

        $this->post('/login', ['email' => $this->user->email, 'password' => 'Clave-actual-123'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $this->user->email, 'password' => 'Nueva-clave-2026'])->assertRedirect('/');
        $this->assertAuthenticatedAs($fresh);
    });

    it('el token es de un solo uso', function () {
        $token = requestResetToken($this->user);

        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email))->assertSessionHasNoErrors();
        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email, 'Otra-clave-2026-x'))->assertSessionHasErrors('token');

        expect(Hash::check('Nueva-clave-2026', $this->user->fresh()->password))->toBeTrue();
    });

    it('el token vence a los 60 minutos', function () {
        $token = requestResetToken($this->user);

        $this->travel(59)->minutes();
        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email, 'Clave-valida-2026'))->assertSessionHasNoErrors();
    });

    it('rechaza un token vencido', function () {
        $token = requestResetToken($this->user);

        $this->travel(61)->minutes();
        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email))->assertSessionHasErrors('token');

        expect(Hash::check('Clave-actual-123', $this->user->fresh()->password))->toBeTrue();
    });

    it('rechaza un token inventado, un email que no corresponde o una cuenta inexistente con el mismo mensaje', function () {
        $token = requestResetToken($this->user);
        $otra = userWithRole('empleado', ['password' => 'Clave-actual-123', 'email' => 'tomas@laia.test']);

        $errores = collect([
            resetPayload('token-inventado', $this->user->email),
            resetPayload($token, $otra->email),            // token de Camila usado para la cuenta de Tomás
            resetPayload($token, 'fantasma@laia.test'),
        ])->map(function (array $payload) {
            $r = $this->post('/restablecer-contrasena', $payload);
            $r->assertSessionHasErrors('token');

            return session('errors')->first('token');
        });

        expect($errores->unique())->toHaveCount(1);
        expect(Hash::check('Clave-actual-123', $otra->fresh()->password))->toBeTrue()
            ->and(Hash::check('Clave-actual-123', $this->user->fresh()->password))->toBeTrue();
    });

    it('rechaza contraseñas débiles, distintas en la confirmación o iguales a la actual', function () {
        $token = requestResetToken($this->user);

        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email, 'corta1'))->assertSessionHasErrors('password');
        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email, 'sololetrasaquinada'))->assertSessionHasErrors('password');
        $this->post('/restablecer-contrasena', [...resetPayload($token, $this->user->email), 'password_confirmation' => 'otra-cosa-2026'])->assertSessionHasErrors('password');
        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email, 'Clave-actual-123'))->assertSessionHasErrors('password');

        // Ninguno de los intentos fallidos consumió el token: todavía sirve.
        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email))->assertSessionHasNoErrors();
    });

    it('sin un token válido no revela si la contraseña propuesta es la actual', function () {
        $r = $this->post('/restablecer-contrasena', resetPayload('token-inventado', $this->user->email, 'Clave-actual-123'));

        $r->assertSessionHasErrors('token')->assertSessionDoesntHaveErrors('password');
    });

    it('no restablece la contraseña de una cuenta dada de baja aunque el token sea válido', function () {
        $token = requestResetToken($this->user);
        $this->user->update(['is_active' => false]);

        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email))->assertSessionHasErrors('token');

        expect(Hash::check('Clave-actual-123', $this->user->fresh()->password))->toBeTrue();
    });

    it('al restablecer se cierran las demás sesiones abiertas de la cuenta', function () {
        $hashAnterior = $this->user->fresh()->password;
        $token = requestResetToken($this->user);
        $this->post('/restablecer-contrasena', resetPayload($token, $this->user->email))->assertSessionHasNoErrors();

        // Una sesión abierta antes del cambio guarda el hash viejo: AuthenticateSession la expulsa.
        $this->actingAs($this->user->fresh())->withSession(['password_hash_web' => $hashAnterior])->get('/')->assertRedirect('/login');
        // Una sesión con el hash actual sigue funcionando.
        $this->actingAs($this->user->fresh())->withSession(['password_hash_web' => $this->user->fresh()->password])->get('/')->assertOk();
    });
});
