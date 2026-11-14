<?php

use App\Mail\PaymentReminderMail;
use App\Models\Client;
use App\Models\ReminderLog;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Mail::fake());

describe('panel de cobranzas', function () {
    it('lista los avisos que corresponden y los cobros en mora, sin mandar nada solo', function () {
        makeInvoice(['due_date' => today()->addDay()->toDateString()]);
        makeInvoice(['due_date' => today()->subDays(2)->toDateString()]);

        $this->actingAs(userWithRole('dueno'))->get('/cobranzas')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Collections/Index')
            ->where('auto', false)
            ->has('notices', 2)
            ->where('stats.overdue.count', 1)
            ->where('stats.upcoming.count', 1));

        Mail::assertNothingQueued();
    });

    it('el cron no manda nada en modo manual y sí en automático', function () {
        makeInvoice(['due_date' => today()->addDay()->toDateString()]);

        $this->artisan('finance:send-reminders')->expectsOutputToContain('Modo manual')->assertSuccessful();
        Mail::assertNothingQueued();

        Settings::set('dunning.reminders_auto', '1');
        $this->artisan('finance:send-reminders')->expectsOutputToContain('antes: 1')->assertSuccessful();
        Mail::assertQueued(PaymentReminderMail::class, 1);
    });

    it('envía los avisos elegidos, una vez, y deja registrado quién los mandó', function () {
        $invoice = makeInvoice(['due_date' => today()->addDay()->toDateString()]);
        $user = userWithRole('dueno');
        $payload = ['items' => [['invoice_id' => $invoice->id, 'type' => 'antes']]];

        $this->actingAs($user)->post('/cobranzas/avisos', $payload)->assertRedirect()->assertSessionHas('success');
        $this->actingAs($user)->post('/cobranzas/avisos', $payload)->assertRedirect()->assertSessionHas('error');

        Mail::assertQueued(PaymentReminderMail::class, 1);
        expect(ReminderLog::where('invoice_id', $invoice->id)->first()->sent_by)->toBe($user->id);
    });

    it('no manda avisos que no corresponden (cobro lejano, ya pagado)', function () {
        $lejano = makeInvoice(['due_date' => today()->addDays(30)->toDateString()]);
        $pagado = makeInvoice(['due_date' => today()->addDay()->toDateString(), 'status' => 'pagada']);

        $this->actingAs(userWithRole('dueno'))->post('/cobranzas/avisos', ['items' => [
            ['invoice_id' => $lejano->id, 'type' => 'antes'],
            ['invoice_id' => $pagado->id, 'type' => 'antes'],
        ]])->assertRedirect();

        Mail::assertNothingQueued();
    });

    it('un cliente con avisos pausados no recibe ninguno, ni a mano ni automático', function () {
        $client = Client::factory()->create(['email' => 'pausa@x.com']);
        $invoice = makeInvoice(['service' => makeService(client: $client), 'due_date' => today()->addDay()->toDateString()]);
        $dueno = userWithRole('dueno');

        $this->actingAs($dueno)->post("/clientes/{$client->id}/avisos", ['paused' => true])->assertRedirect();
        expect($client->fresh()->reminders_paused)->toBeTrue();

        $this->actingAs($dueno)->post('/cobranzas/avisos', ['items' => [['invoice_id' => $invoice->id, 'type' => 'antes']]])->assertRedirect();
        Settings::set('dunning.reminders_auto', '1');
        $this->artisan('finance:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();

        $this->actingAs($dueno)->post("/clientes/{$client->id}/avisos", ['paused' => false])->assertRedirect();
        $this->actingAs($dueno)->post('/cobranzas/avisos', ['items' => [['invoice_id' => $invoice->id, 'type' => 'antes']]])->assertRedirect();
        Mail::assertQueued(PaymentReminderMail::class, 1);
    });

    it('sin email no sale el aviso y se informa', function () {
        $client = Client::factory()->create(['email' => null]);
        $invoice = makeInvoice(['service' => makeService(client: $client), 'due_date' => today()->addDay()->toDateString()]);

        $this->actingAs(userWithRole('dueno'))->post('/cobranzas/avisos', ['items' => [['invoice_id' => $invoice->id, 'type' => 'antes']]])
            ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'sin email'));

        Mail::assertNothingQueued();
        expect(ReminderLog::count())->toBe(0);
    });

    it('la vista previa muestra asunto y correo sin enviar nada', function () {
        $invoice = makeInvoice(['due_date' => today()->addDays(2)->toDateString()]);

        $this->actingAs(userWithRole('dueno'))->getJson("/cobranzas/avisos/{$invoice->id}/antes/vista-previa")->assertOk()
            ->assertJsonPath('to', $invoice->client->email)
            ->assertJsonFragment(['subject' => "Tu cobro {$invoice->number} vence en 2 días · \$ 280.000,00"])
            ->assertJsonStructure(['html']);

        $this->actingAs(userWithRole('dueno'))->getJson("/cobranzas/avisos/{$invoice->id}/otro/vista-previa")->assertNotFound();
        Mail::assertNothingQueued();
    });

    it('cambiar el modo y enviar requiere gestionar finanzas; ver el panel no', function () {
        $invoice = makeInvoice(['due_date' => today()->addDay()->toDateString()]);
        $empleado = userWithRole('empleado');

        $this->actingAs($empleado)->get('/cobranzas')->assertForbidden();
        $this->actingAs($empleado)->post('/cobranzas/modo', ['auto' => true])->assertForbidden();
        $this->actingAs($empleado)->post('/cobranzas/avisos', ['items' => [['invoice_id' => $invoice->id, 'type' => 'antes']]])->assertForbidden();

        $this->actingAs(userWithRole('dueno'))->post('/cobranzas/modo', ['auto' => true])->assertRedirect();
        expect(Settings::get('dunning.reminders_auto'))->toBe('1');
    });
});
