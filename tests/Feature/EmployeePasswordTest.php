<?php

use App\Models\User;
use App\Notifications\PasswordChangedByAdmin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->dueno = userWithRole('dueno');
    $this->empleado = userWithRole('empleado', ['password' => 'Clave-vieja-123']);
});

it('genera una contraseña nueva, la devuelve una sola vez y la guarda con hash', function () {
    $response = $this->actingAs($this->dueno)->postJson("/equipo/{$this->empleado->id}/clave", ['mode' => 'generate'])->assertOk();

    $plain = $response->json('password');
    expect($plain)->toHaveLength(14)->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/')->not->toMatch('/[0OIl1]/')
        ->and(Hash::check($plain, $this->empleado->fresh()->password))->toBeTrue()
        ->and(Hash::check('Clave-vieja-123', $this->empleado->fresh()->password))->toBeFalse();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('cada generación es distinta', function () {
    $a = $this->actingAs($this->dueno)->postJson("/equipo/{$this->empleado->id}/clave", ['mode' => 'generate'])->json('password');
    $b = $this->actingAs($this->dueno)->postJson("/equipo/{$this->empleado->id}/clave", ['mode' => 'generate'])->json('password');

    expect($a)->not->toBe($b);
});

it('permite fijar una contraseña elegida, con confirmación y reglas de seguridad', function () {
    $url = "/equipo/{$this->empleado->id}/clave";

    $this->actingAs($this->dueno)->postJson($url, ['mode' => 'set', 'password' => 'corta1', 'password_confirmation' => 'corta1'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->actingAs($this->dueno)->postJson($url, ['mode' => 'set', 'password' => 'Laia-nueva-2026', 'password_confirmation' => 'otra'])->assertUnprocessable();
    $this->actingAs($this->dueno)->postJson($url, ['mode' => 'set'])->assertUnprocessable();

    $this->actingAs($this->dueno)->postJson($url, ['mode' => 'set', 'password' => 'Laia-nueva-2026', 'password_confirmation' => 'Laia-nueva-2026'])
        ->assertOk()->assertJson(['ok' => true])->assertJsonMissingPath('password');

    expect(Hash::check('Laia-nueva-2026', $this->empleado->fresh()->password))->toBeTrue();
});

it('avisa a la persona, cierra sus sesiones recordadas y audita sin guardar la clave', function () {
    $this->empleado->forceFill(['remember_token' => 'abc'])->save();

    $plain = $this->actingAs($this->dueno)->postJson("/equipo/{$this->empleado->id}/clave", ['mode' => 'generate'])->json('password');

    Notification::assertSentTo($this->empleado, PasswordChangedByAdmin::class);
    expect($this->empleado->fresh()->remember_token)->toBeNull();

    $log = Activity::where('description', 'Contraseña cambiada por un administrador')->firstOrFail();
    expect($log->causer_id)->toBe($this->dueno->id)->and($log->subject_id)->toBe($this->empleado->id)
        ->and(json_encode($log->properties))->not->toContain($plain);
});

it('el correo de aviso no contiene la contraseña', function () {
    $mail = (new PasswordChangedByAdmin('Matías'))->toMail($this->empleado);

    expect($mail->viewData['bodyMarkdown'])->toContain('Matías')->and($mail->subject)->toContain('contraseña');
});

it('no se puede cambiar la de uno mismo, ni la de un dueño siendo dueño, ni la de un sysadmin', function () {
    $otroDueno = userWithRole('dueno');
    $sysadmin = userWithRole('sysadmin');

    $this->actingAs($this->dueno)->postJson("/equipo/{$this->dueno->id}/clave", ['mode' => 'generate'])->assertForbidden();
    $this->actingAs($this->dueno)->postJson("/equipo/{$otroDueno->id}/clave", ['mode' => 'generate'])->assertForbidden();
    $this->actingAs($this->dueno)->postJson("/equipo/{$sysadmin->id}/clave", ['mode' => 'generate'])->assertForbidden();
    $this->actingAs($sysadmin)->postJson("/equipo/{$sysadmin->id}/clave", ['mode' => 'generate'])->assertForbidden();

    // El sysadmin sí puede con un dueño.
    $this->actingAs($sysadmin)->postJson("/equipo/{$otroDueno->id}/clave", ['mode' => 'generate'])->assertOk();
});

it('un empleado no puede cambiar contraseñas ajenas', function () {
    $otro = userWithRole('empleado');

    $this->actingAs($this->empleado)->postJson("/equipo/{$otro->id}/clave", ['mode' => 'generate'])->assertForbidden();
});

it('no se cambia la contraseña de alguien dado de baja', function () {
    $this->empleado->update(['is_active' => false]);

    $this->actingAs($this->dueno)->postJson("/equipo/{$this->empleado->id}/clave", ['mode' => 'generate'])->assertUnprocessable();
});

it('la nueva contraseña permite iniciar sesión y la anterior ya no', function () {
    $plain = $this->actingAs($this->dueno)->postJson("/equipo/{$this->empleado->id}/clave", ['mode' => 'generate'])->json('password');
    auth()->logout();

    $this->post('/login', ['email' => $this->empleado->email, 'password' => 'Clave-vieja-123'])->assertSessionHasErrors();
    $this->post('/login', ['email' => $this->empleado->email, 'password' => $plain])->assertSessionHasNoErrors();
});
