<?php

use App\Mail\PaymentReminderMail;
use App\Support\BankAccount;
use App\Support\Settings;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

const CBU = '0170099220000067797370';

describe('datos bancarios del empleado', function () {
    it('cada persona carga los suyos y se guardan normalizados', function () {
        $user = userWithRole('empleado');

        $this->actingAs($user)->put('/perfil/datos-bancarios', [
            'bank_holder' => 'Matías Ríos', 'bank_tax_id' => '20-12345678-9', 'bank_name' => 'Mercado Pago',
            'bank_cbu' => '0170 0992 2000 0067 7973 70', 'bank_alias' => ' matias.web ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        expect($user->bank_cbu)->toBe(CBU)->and($user->bank_tax_id)->toBe('20123456789')->and($user->bank_alias)->toBe('matias.web');
    });

    it('valida CBU, CUIT y alias, y deja vacíos en null', function () {
        $user = userWithRole('empleado');

        $this->actingAs($user)->put('/perfil/datos-bancarios', ['bank_cbu' => '123', 'bank_tax_id' => '999', 'bank_alias' => 'a b'])
            ->assertSessionHasErrors(['bank_cbu', 'bank_tax_id', 'bank_alias']);

        $this->actingAs($user)->put('/perfil/datos-bancarios', ['bank_holder' => '', 'bank_cbu' => ''])->assertSessionHasNoErrors();
        expect($user->fresh()->bank_cbu)->toBeNull();
    });

    it('Dirección edita los de un empleado; un empleado no edita los de otro ni Dirección los de otro dueño', function () {
        $empleado = userWithRole('empleado');
        $otro = userWithRole('empleado');
        $dueno = userWithRole('dueno');
        $payload = ['bank_alias' => 'equipo.cuenta', 'bank_cbu' => CBU];

        $this->actingAs($dueno)->put("/equipo/{$empleado->id}/datos-bancarios", $payload)->assertRedirect();
        expect($empleado->fresh()->bank_alias)->toBe('equipo.cuenta');

        $this->actingAs($otro)->put("/equipo/{$empleado->id}/datos-bancarios", $payload)->assertForbidden();
        $this->actingAs($dueno)->put("/equipo/{$dueno->id}/datos-bancarios", $payload)->assertForbidden();
    });

    it('la auditoría registra qué campos cambiaron, nunca los valores', function () {
        $user = userWithRole('empleado');

        $this->actingAs($user)->put('/perfil/datos-bancarios', ['bank_cbu' => CBU, 'bank_alias' => 'mi.alias.privado'])->assertRedirect();

        $log = Activity::where('description', 'Datos bancarios actualizados')->latest('id')->first();
        expect($log->properties['fields'])->toContain('bank_cbu', 'bank_alias')
            ->and(json_encode($log->properties))->not->toContain('mi.alias.privado')->not->toContain(CBU);
    });

    it('solo Dirección y la propia persona reciben los datos en la pantalla', function () {
        $empleado = userWithRole('empleado', ['bank_alias' => 'privado.emp', 'bank_cbu' => CBU]);

        $this->actingAs(userWithRole('dueno'))->get('/equipo')->assertInertia(fn (Assert $p) => $p
            ->where('employees', fn ($rows) => collect($rows)->firstWhere('id', $empleado->id)['bank']['bank_alias'] === 'privado.emp'));

        $this->actingAs($empleado)->get('/equipo')->assertForbidden();
        $this->actingAs($empleado)->get('/perfil')->assertInertia(fn (Assert $p) => $p->where('bank.bank_cbu', CBU));
    });
});

describe('cuenta que ve el cliente en el correo de cobro', function () {
    beforeEach(fn () => Settings::set('bank.alias', 'agencia.alias'));

    it('usa la cuenta de quien tiene el servicio a cargo', function () {
        $matias = userWithRole('empleado', ['name' => 'Matías Web', 'bank_alias' => 'matias.web', 'bank_holder' => 'Matías Web']);
        $invoice = makeInvoice(['service' => makeService(['assigned_user_id' => $matias->id])]);

        $mail = new PaymentReminderMail($invoice, 'antes');
        $mail->assertSeeInHtml('matias.web');
        $mail->assertDontSeeInHtml('agencia.alias');
        expect(BankAccount::forInvoice($invoice)['owner'])->toBe('Matías Web');
    });

    it('cada servicio usa la cuenta de su responsable', function () {
        $web = userWithRole('empleado', ['bank_alias' => 'web.alias']);
        $cm = userWithRole('empleado', ['bank_alias' => 'cm.alias']);
        $client = \App\Models\Client::factory()->create(['email' => 'c@x.com']);

        $invWeb = makeInvoice(['service' => makeService(['name' => 'Web', 'assigned_user_id' => $web->id], $client)]);
        $invCm = makeInvoice(['service' => makeService(['name' => 'CM', 'assigned_user_id' => $cm->id], $client)]);

        (new PaymentReminderMail($invWeb, 'antes'))->assertSeeInHtml('web.alias');
        (new PaymentReminderMail($invCm, 'antes'))->assertSeeInHtml('cm.alias');
    });

    it('sin datos del empleado o sin responsable, cae a la cuenta de la agencia y lo avisa', function () {
        $sinDatos = userWithRole('empleado');
        $conEmpleado = makeInvoice(['service' => makeService(['assigned_user_id' => $sinDatos->id])]);
        $sinNadie = makeInvoice();

        (new PaymentReminderMail($conEmpleado, 'antes'))->assertSeeInHtml('agencia.alias');
        (new PaymentReminderMail($sinNadie, 'antes'))->assertSeeInHtml('agencia.alias');
        expect(BankAccount::forInvoice($conEmpleado)['missing_employee'])->toBeTrue()
            ->and(BankAccount::forInvoice($sinNadie)['missing_employee'])->toBeFalse();
    });

    it('Cobranzas informa a qué cuenta va a pagar el cliente en cada aviso', function () {
        $matias = userWithRole('empleado', ['name' => 'Matías Web', 'bank_alias' => 'matias.web']);
        makeInvoice(['service' => makeService(['assigned_user_id' => $matias->id]), 'due_date' => today()->addDay()->toDateString()]);

        $this->actingAs(userWithRole('dueno'))->get('/cobranzas')->assertInertia(fn (Assert $p) => $p
            ->where('notices.0.account.source', 'empleado')->where('notices.0.account.owner', 'Matías Web'));
    });
});
