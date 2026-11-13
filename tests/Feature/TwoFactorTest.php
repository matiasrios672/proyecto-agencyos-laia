<?php

use App\Models\User;
use App\Notifications\RecoveryCodeUsed;
use App\Notifications\TwoFactorWasReset;
use App\Support\Settings;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/** Usuario con 2FA ya activo. @return array{0: User, 1: string, 2: list<string>} usuario, secreto y códigos de recuperación en claro */
function userWithTwoFactor(string $role = 'empleado', array $attrs = []): array
{
    $user = userWithRole($role, ['password' => 'Clave-actual-123'] + $attrs);
    $twoFactor = app(TwoFactor::class);
    $secret = $twoFactor->newSecret();
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => []])->save();
    $codes = $twoFactor->regenerateRecoveryCodes($user);

    return [$user->fresh(), $secret, $codes];
}

/** Código TOTP del paso actual (±offset pasos de 30 s). */
function totp(string $secret, int $offsetSteps = 0): string
{
    $g = app(Google2FA::class);

    return $g->oathTotp($secret, $g->getTimestamp() + $offsetSteps);
}

/** Estado de sesión que deja el paso 1 del login (contraseña correcta, falta el código). */
function pendingTwoFactor(User $user, array $override = []): array
{
    return ['two_factor_pending' => array_replace(['id' => $user->id, 'remember' => false, 'expires_at' => now()->addMinutes(5)->timestamp], $override)];
}

describe('alta desde el perfil', function () {
    beforeEach(fn () => $this->user = userWithRole('empleado', ['password' => 'Clave-actual-123']));

    it('empieza el alta con un secreto pendiente y lo entrega solo mientras dura el alta', function () {
        $this->actingAs($this->user)->post('/perfil/2fa')->assertRedirect();

        $fresh = $this->user->fresh();
        expect($fresh->hasPendingTwoFactor())->toBeTrue()->and($fresh->hasTwoFactorEnabled())->toBeFalse();

        $this->actingAs($fresh)->get('/perfil')->assertInertia(fn ($p) => $p
            ->where('twoFactor.pending', true)
            ->where('twoFactor.enabled', false)
            ->where('twoFactor.secret', $fresh->two_factor_secret)
            ->where('twoFactor.otpauth', fn ($uri) => str_starts_with($uri, 'otpauth://totp/') && str_contains($uri, 'secret='.$fresh->two_factor_secret) && str_contains($uri, 'camila') === false));
    });

    it('guarda el secreto cifrado en la base', function () {
        $this->actingAs($this->user)->post('/perfil/2fa');

        $raw = DB::table('users')->where('id', $this->user->id)->value('two_factor_secret');

        expect($raw)->not->toBe($this->user->fresh()->two_factor_secret)->and(strlen($raw))->toBeGreaterThan(60);
    });

    it('un código incorrecto no activa nada', function () {
        $this->actingAs($this->user)->post('/perfil/2fa');

        $this->actingAs($this->user->fresh())->post('/perfil/2fa/confirmar', ['code' => '000000'])->assertSessionHasErrors('code');

        $fresh = $this->user->fresh();
        expect($fresh->hasTwoFactorEnabled())->toBeFalse()->and($fresh->hasPendingTwoFactor())->toBeTrue();
    });

    it('con el código correcto se activa y entrega 10 códigos de recuperación una sola vez', function () {
        $this->actingAs($this->user)->post('/perfil/2fa');
        $secret = $this->user->fresh()->two_factor_secret;

        $this->actingAs($this->user->fresh())->post('/perfil/2fa/confirmar', ['code' => totp($secret)])
            ->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('recovery_codes', fn ($codes) => count($codes) === 10 && collect($codes)->every(fn ($c) => preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $c)) && count(array_unique($codes)) === 10);

        $fresh = $this->user->fresh();
        expect($fresh->hasTwoFactorEnabled())->toBeTrue()
            ->and($fresh->two_factor_last_step)->not->toBeNull()
            ->and(app(TwoFactor::class)->remainingRecoveryCodes($fresh))->toBe(10);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'Verificación en dos pasos activada']);
    });

    it('los códigos de recuperación no se guardan en claro', function () {
        $this->actingAs($this->user)->post('/perfil/2fa');
        $secret = $this->user->fresh()->two_factor_secret;
        $response = $this->actingAs($this->user->fresh())->post('/perfil/2fa/confirmar', ['code' => totp($secret)]);
        $plain = session('recovery_codes');

        $stored = $this->user->fresh()->two_factor_recovery_codes;

        expect($stored)->toHaveCount(10)->and(array_intersect($stored, $plain))->toBeEmpty()
            ->and(collect($stored)->every(fn ($h) => preg_match('/^[a-f0-9]{64}$/', $h)))->toBeTrue();
        expect(DB::table('users')->where('id', $this->user->id)->value('two_factor_recovery_codes'))->not->toContain($plain[0]);
        unset($response);
    });

    it('con 2FA activo nunca vuelve a mandar el secreto al navegador', function () {
        [$user, $secret, $codes] = userWithTwoFactor();

        $html = $this->actingAs($user)->get('/perfil')->assertInertia(fn ($p) => $p
            ->where('twoFactor.enabled', true)->where('twoFactor.pending', false)
            ->where('twoFactor.secret', null)->where('twoFactor.otpauth', null)
            ->where('twoFactor.recovery_remaining', 10)->where('recoveryCodes', null))->getContent();

        expect($html)->not->toContain($secret)->not->toContain($codes[0]);
        $this->actingAs($user)->get('/')->assertDontSee($secret);
        expect($user->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step']);
    });

    it('muestra los códigos recién generados una sola vez (flash) y después ya no', function () {
        [$user] = userWithTwoFactor();

        $this->actingAs($user)->post('/perfil/2fa/codigos', ['password' => 'Clave-actual-123'])->assertSessionHas('recovery_codes');
        $nuevos = session('recovery_codes');

        // La primera pantalla que se carga después los muestra...
        $this->actingAs($user)->get('/perfil')->assertInertia(fn ($p) => $p->where('recoveryCodes', $nuevos)->where('twoFactor.recovery_remaining', 10));
        // ...y la siguiente ya no.
        $this->actingAs($user)->get('/perfil')->assertInertia(fn ($p) => $p->where('recoveryCodes', null));
    });

    it('no se puede empezar un alta si ya está activo, ni confirmar sin alta pendiente', function () {
        [$user, $secret] = userWithTwoFactor();

        $this->actingAs($user)->post('/perfil/2fa')->assertStatus(422);
        $this->actingAs($this->user)->post('/perfil/2fa/confirmar', ['code' => totp($secret)])->assertSessionHasErrors('code');
        expect($this->user->fresh()->hasTwoFactorEnabled())->toBeFalse();
    });

    it('cancela un alta pendiente', function () {
        $this->actingAs($this->user)->post('/perfil/2fa');

        $this->actingAs($this->user->fresh())->delete('/perfil/2fa/pendiente')->assertRedirect();

        expect($this->user->fresh()->two_factor_secret)->toBeNull();
        $this->actingAs($this->user->fresh())->delete('/perfil/2fa/pendiente')->assertStatus(422);
    });

    it('limita los intentos de confirmación', function () {
        $this->actingAs($this->user)->post('/perfil/2fa');
        $secret = $this->user->fresh()->two_factor_secret;

        foreach (range(1, 5) as $_) {
            $this->actingAs($this->user->fresh())->post('/perfil/2fa/confirmar', ['code' => '111111'])->assertSessionHasErrors('code');
        }

        $this->actingAs($this->user->fresh())->post('/perfil/2fa/confirmar', ['code' => totp($secret)])->assertSessionHasErrors('code');
        expect($this->user->fresh()->hasTwoFactorEnabled())->toBeFalse();
    });

    it('exige sesión', function () {
        $this->post('/perfil/2fa')->assertRedirect('/login');
        $this->post('/perfil/2fa/confirmar', ['code' => '123456'])->assertRedirect('/login');
        $this->delete('/perfil/2fa')->assertRedirect('/login');
    });
});

describe('desactivar y regenerar códigos', function () {
    it('desactivar pide la contraseña actual', function () {
        [$user] = userWithTwoFactor();

        $this->actingAs($user)->delete('/perfil/2fa', ['password' => 'incorrecta'])->assertSessionHasErrors('password');
        $this->actingAs($user)->delete('/perfil/2fa', [])->assertSessionHasErrors('password');
        expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

        $this->actingAs($user)->delete('/perfil/2fa', ['password' => 'Clave-actual-123'])->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        expect($fresh->hasTwoFactorEnabled())->toBeFalse()->and($fresh->two_factor_secret)->toBeNull()
            ->and($fresh->two_factor_recovery_codes)->toBeNull()->and($fresh->two_factor_last_step)->toBeNull();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'Verificación en dos pasos desactivada']);
    });

    it('regenerar códigos pide la contraseña y los anteriores dejan de servir', function () {
        [$user, $secret, $viejos] = userWithTwoFactor();

        $this->actingAs($user)->post('/perfil/2fa/codigos', ['password' => 'incorrecta'])->assertSessionHasErrors('password');
        expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(10);

        $this->actingAs($user)->post('/perfil/2fa/codigos', ['password' => 'Clave-actual-123'])
            ->assertSessionHas('recovery_codes', fn ($c) => count($c) === 10 && array_intersect($c, $viejos) === []);

        expect(app(TwoFactor::class)->attempt($user->id, $viejos[0]))->toBeNull();
    });

    it('regenerar o desactivar sin 2FA activo da 422', function () {
        $user = userWithRole('empleado', ['password' => 'Clave-actual-123']);

        $this->actingAs($user)->post('/perfil/2fa/codigos', ['password' => 'Clave-actual-123'])->assertStatus(422);
        $this->actingAs($user)->delete('/perfil/2fa', ['password' => 'Clave-actual-123'])->assertStatus(422);
    });

    it('si la política lo exige, Dirección no puede desactivarlo; un empleado sí', function () {
        Settings::set('security.require_2fa_privileged', '1');
        [$duena] = userWithTwoFactor('dueno');
        [$camila] = userWithTwoFactor('empleado');

        $this->actingAs($duena)->delete('/perfil/2fa', ['password' => 'Clave-actual-123'])->assertSessionHasErrors('password');
        expect($duena->fresh()->hasTwoFactorEnabled())->toBeTrue();

        $this->actingAs($camila)->delete('/perfil/2fa', ['password' => 'Clave-actual-123'])->assertSessionHasNoErrors();
        expect($camila->fresh()->hasTwoFactorEnabled())->toBeFalse();
    });

    it('cambiar la contraseña del perfil sigue exigiendo la contraseña actual', function () {
        [$user] = userWithTwoFactor();

        $this->actingAs($user)->put('/perfil/contrasena', ['password' => 'Nueva-clave-2026', 'password_confirmation' => 'Nueva-clave-2026'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/perfil/contrasena', ['current_password' => 'mal', 'password' => 'Nueva-clave-2026', 'password_confirmation' => 'Nueva-clave-2026'])
            ->assertSessionHasErrors('current_password');
    });
});

describe('login con verificación en dos pasos', function () {
    it('tras la contraseña NO inicia sesión: queda pendiente del código', function () {
        [$user] = userWithTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'Clave-actual-123'])->assertRedirect('/verificacion-dos-pasos')
            ->assertSessionHas('two_factor_pending', fn ($p) => $p['id'] === $user->id && $p['expires_at'] > now()->timestamp);

        $this->assertGuest();
        auth()->logout();
        $this->get('/')->assertRedirect('/login');
        $this->get('/clientes')->assertRedirect('/login');
    });

    it('una contraseña incorrecta no avanza al segundo paso', function () {
        [$user] = userWithTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'mal'])->assertSessionHasErrors('email')->assertSessionMissing('two_factor_pending');
    });

    it('un usuario sin 2FA entra directo, como siempre', function () {
        $user = userWithRole('empleado', ['password' => 'Clave-actual-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Clave-actual-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    });

    it('un alta de 2FA sin confirmar no exige código', function () {
        $user = userWithRole('empleado', ['password' => 'Clave-actual-123']);
        $user->forceFill(['two_factor_secret' => app(TwoFactor::class)->newSecret()])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'Clave-actual-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    });

    it('el desafío sin intento previo (o vencido) vuelve al login', function () {
        [$user, $secret] = userWithTwoFactor();

        $this->get('/verificacion-dos-pasos')->assertRedirect('/login');
        $this->post('/verificacion-dos-pasos', ['code' => totp($secret)])->assertRedirect('/login');

        $this->withSession(pendingTwoFactor($user, ['expires_at' => now()->subSecond()->timestamp]))
            ->post('/verificacion-dos-pasos', ['code' => totp($secret)])->assertRedirect('/login');
        $this->assertGuest();
    });

    it('con el código correcto inicia sesión, limpia el estado pendiente y audita', function () {
        [$user, $secret] = userWithTwoFactor();

        $this->withSession(pendingTwoFactor($user))->get('/verificacion-dos-pasos')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Auth/TwoFactorChallenge', false));

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => totp($secret)])->assertRedirect('/')
            ->assertSessionMissing('two_factor_pending');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'Ingreso con verificación en dos pasos', 'causer_id' => $user->id]);
    });

    it('acepta el código con espacios y tolera ±30 segundos de desfase del reloj', function () {
        [$user, $secret] = userWithTwoFactor();

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => substr(totp($secret, -1), 0, 3).' '.substr(totp($secret, -1), 3)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    });

    it('rechaza un código incorrecto y deja constancia', function () {
        [$user] = userWithTwoFactor();

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => '123456'])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'Código de verificación incorrecto']);
    });

    it('rechaza un código vencido (de hace 5 minutos)', function () {
        [$user, $secret] = userWithTwoFactor();

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => totp($secret, -10)])->assertSessionHasErrors('code');
        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => totp($secret, 10)])->assertSessionHasErrors('code');

        $this->assertGuest();
    });

    it('un código ya usado no sirve de nuevo (ni uno anterior a él)', function () {
        [$user, $secret] = userWithTwoFactor();
        $codigo = totp($secret);

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => $codigo])->assertRedirect('/');
        auth()->logout();

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => $codigo])->assertSessionHasErrors('code');
        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => totp($secret, -1)])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('un código de recuperación entra una sola vez y avisa por notificación', function () {
        [$user, $secret, $codes] = userWithTwoFactor();

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => $codes[0]])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        expect(app(TwoFactor::class)->remainingRecoveryCodes($user->fresh()))->toBe(9);
        expect($user->notifications()->where('type', RecoveryCodeUsed::class)->count())->toBe(1);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'Ingreso con código de recuperación']);

        auth()->logout();
        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
        // El resto de los códigos sigue valiendo.
        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => $codes[1]])->assertRedirect('/');
    });

    it('el código de recuperación se acepta en mayúsculas, sin guion o con espacios', function () {
        [$user, , $codes] = userWithTwoFactor();
        $twoFactor = app(TwoFactor::class);

        expect($twoFactor->attempt($user->id, strtoupper($codes[0])))->toBe('recovery')
            ->and($twoFactor->attempt($user->id, str_replace('-', '', $codes[1])))->toBe('recovery')
            ->and($twoFactor->attempt($user->id, ' '.str_replace('-', ' ', $codes[2]).' '))->toBe('recovery')
            ->and($twoFactor->attempt($user->id, 'zzzzz-zzzzz'))->toBeNull()
            ->and($twoFactor->remainingRecoveryCodes($user->fresh()))->toBe(7);
    });

    it('limita los intentos: tras 5 fallos ni el código correcto pasa', function () {
        [$user, $secret] = userWithTwoFactor();

        foreach (range(1, 5) as $_) {
            $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => '111111'])->assertSessionHasErrors('code');
        }

        // Ni el código correcto pasa mientras dure el bloqueo.
        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => totp($secret)])->assertSessionHasErrors('code');
        $this->assertGuest();
        expect(session('errors')->first('code'))->toContain('Demasiados intentos');
    });

    it('si la cuenta se dio de baja entre los dos pasos, no entra', function () {
        [$user, $secret] = userWithTwoFactor();
        $user->update(['is_active' => false]);

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', ['code' => totp($secret)])->assertRedirect('/login');

        $this->assertGuest();
    });

    it('el estado pendiente de un usuario no sirve para entrar como otro', function () {
        [$a] = userWithTwoFactor();
        [$b, $secretB] = userWithTwoFactor();

        // Sesión pendiente de A + código de B: no entra.
        $this->withSession(pendingTwoFactor($a))->post('/verificacion-dos-pasos', ['code' => totp($secretB)])->assertSessionHasErrors('code');

        $this->assertGuest();
        expect($b->id)->not->toBe($a->id);
    });

    it('valida el campo código', function () {
        [$user] = userWithTwoFactor();

        $this->withSession(pendingTwoFactor($user))->post('/verificacion-dos-pasos', [])->assertSessionHasErrors('code');
    });
});

describe('obligatoriedad para Superadmin y Dirección', function () {
    it('con la política activa, un privilegiado sin 2FA solo puede usar el perfil, notificaciones y salir', function (string $role) {
        Settings::set('security.require_2fa_privileged', '1');
        $user = userWithRole($role);

        $this->actingAs($user)->get('/clientes')->assertRedirect('/perfil')->assertSessionHas('error');
        $this->actingAs($user)->get('/')->assertRedirect('/perfil');
        $this->actingAs($user)->get('/perfil')->assertOk();
        $this->actingAs($user)->post('/perfil/2fa')->assertRedirect();
        $this->actingAs($user)->getJson('/notificaciones')->assertOk();
        $this->actingAs($user)->getJson('/clientes')->assertStatus(423);
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
    })->with(['sysadmin', 'dueno']);

    it('no afecta a empleados, ni a privilegiados que ya activaron el 2FA, ni con la política apagada', function () {
        Settings::set('security.require_2fa_privileged', '1');
        $camila = userWithRole('empleado');
        [$duena] = userWithTwoFactor('dueno');

        $this->actingAs($camila)->get('/clientes')->assertOk();
        $this->actingAs($duena)->get('/clientes')->assertOk();

        Settings::set('security.require_2fa_privileged', '0');
        $this->actingAs(userWithRole('sysadmin'))->get('/clientes')->assertOk();
    });

    it('al activar el 2FA recupera el acceso completo', function () {
        Settings::set('security.require_2fa_privileged', '1');
        $admin = userWithRole('sysadmin');

        $this->actingAs($admin)->post('/perfil/2fa');
        $secret = $admin->fresh()->two_factor_secret;
        $this->actingAs($admin->fresh())->post('/perfil/2fa/confirmar', ['code' => totp($secret)])->assertSessionHasNoErrors();

        $this->actingAs($admin->fresh())->get('/clientes')->assertOk();
    });

    it('el sysadmin puede activar la política desde Configuración', function () {
        // Con 2FA propio: apenas se activa la política, un sysadmin sin 2FA quedaría restringido al perfil.
        [$admin] = userWithTwoFactor('sysadmin');

        $this->actingAs($admin)->put('/admin/configuracion', ['values' => ['security.require_2fa_privileged' => '1']])->assertRedirect();

        expect(Settings::get('security.require_2fa_privileged'))->toBe('1');
        $this->actingAs($admin)->get('/admin/configuracion')->assertInertia(fn ($p) => $p->has('groups.Seguridad.0', fn ($f) => $f
            ->where('key', 'security.require_2fa_privileged')->where('type', 'boolean')->where('value', '1')->etc()));
    });
});

describe('restablecer el 2FA de otra persona (dispositivo perdido)', function () {
    it('Dirección restablece el de un empleado, queda auditado y se le avisa', function () {
        $duena = userWithRole('dueno');
        [$camila] = userWithTwoFactor();

        $this->actingAs($duena)->post("/equipo/{$camila->id}/2fa/restablecer")->assertRedirect()->assertSessionHas('success');

        $fresh = $camila->fresh();
        expect($fresh->hasTwoFactorEnabled())->toBeFalse()->and($fresh->two_factor_secret)->toBeNull()->and($fresh->two_factor_recovery_codes)->toBeNull();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'security', 'description' => 'Verificación en dos pasos restablecida por un administrador', 'causer_id' => $duena->id, 'subject_id' => $camila->id]);
        expect($camila->notifications()->where('type', TwoFactorWasReset::class)->count())->toBe(1);

        // Sin dispositivo, ahora ingresa solo con la contraseña.
        $this->post('/login', ['email' => $camila->email, 'password' => 'Clave-actual-123'])->assertRedirect('/');
    });

    it('el sysadmin puede restablecer el de Dirección y el de empleados, pero no el propio', function () {
        $admin = userWithRole('sysadmin');
        [$duena] = userWithTwoFactor('dueno');
        [$camila] = userWithTwoFactor();
        $admin->forceFill(['two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin)->post("/equipo/{$duena->id}/2fa/restablecer")->assertRedirect();
        $this->actingAs($admin)->post("/equipo/{$camila->id}/2fa/restablecer")->assertRedirect();
        $this->actingAs($admin)->post("/equipo/{$admin->id}/2fa/restablecer")->assertForbidden();

        expect($duena->fresh()->hasTwoFactorEnabled())->toBeFalse()->and($camila->fresh()->hasTwoFactorEnabled())->toBeFalse();
    });

    it('Dirección no puede restablecer el de otra Dirección ni el del sysadmin', function () {
        $duena = userWithRole('dueno');
        [$otra] = userWithTwoFactor('dueno');
        [$admin] = userWithTwoFactor('sysadmin');

        $this->actingAs($duena)->post("/equipo/{$otra->id}/2fa/restablecer")->assertForbidden();
        $this->actingAs($duena)->post("/equipo/{$admin->id}/2fa/restablecer")->assertForbidden();
        $this->actingAs($duena)->post("/equipo/{$duena->id}/2fa/restablecer")->assertForbidden();

        expect($otra->fresh()->hasTwoFactorEnabled())->toBeTrue()->and($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
    });

    it('un empleado no puede restablecer nada', function () {
        [$camila] = userWithTwoFactor();
        [$tomas] = userWithTwoFactor();

        $this->actingAs($camila)->post("/equipo/{$tomas->id}/2fa/restablecer")->assertForbidden();

        expect($tomas->fresh()->hasTwoFactorEnabled())->toBeTrue();
    });

    it('da 422 si la persona no tiene 2FA', function () {
        $duena = userWithRole('dueno');
        $camila = userWithRole('empleado');

        $this->actingAs($duena)->post("/equipo/{$camila->id}/2fa/restablecer")->assertStatus(422);
    });

    it('la lista de equipo marca quién tiene 2FA sin exponer secretos', function () {
        $duena = userWithRole('dueno');
        [$camila, $secret, $codes] = userWithTwoFactor();
        userWithRole('empleado');

        $response = $this->actingAs($duena)->get('/equipo')->assertInertia(fn ($p) => $p
            ->where('employees', fn ($list) => collect($list)->firstWhere('id', $camila->id)['two_factor'] === true
                && collect($list)->where('two_factor', false)->count() >= 1));

        expect($response->getContent())->not->toContain($secret)->not->toContain($codes[0]);
    });
});

describe('TwoFactor (unidad)', function () {
    it('genera 10 códigos de recuperación únicos con el formato esperado', function () {
        [$user] = userWithTwoFactor();
        $codes = app(TwoFactor::class)->regenerateRecoveryCodes($user);

        expect($codes)->toHaveCount(10)->and(array_unique($codes))->toHaveCount(10);
    });

    it('dos envíos del mismo código no pasan los dos (consumo atómico)', function () {
        [$user, $secret] = userWithTwoFactor();
        $twoFactor = app(TwoFactor::class);
        $codigo = totp($secret);

        expect($twoFactor->attempt($user->id, $codigo))->toBe('totp')->and($twoFactor->attempt($user->id, $codigo))->toBeNull();
    });

    it('no valida para un usuario sin 2FA o inexistente', function () {
        $user = userWithRole('empleado');

        expect(app(TwoFactor::class)->attempt($user->id, '123456'))->toBeNull()->and(app(TwoFactor::class)->attempt(999999, '123456'))->toBeNull();
    });
});
