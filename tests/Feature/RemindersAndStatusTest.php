<?php

use App\Actions\Finance\SyncAccountStatus;
use App\Enums\AccountStatus;
use App\Mail\PaymentReminderMail;
use App\Models\Client;
use App\Models\ReminderLog;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;

describe('semáforo del cliente', function () {
    it('deuda si hay un cobro pendiente vencido', function () {
        $service = makeService();
        makeInvoice(['service' => $service, 'due_date' => today()->subDay()->toDateString()]);

        expect(app(SyncAccountStatus::class)->forClient($service->client))->toBe(AccountStatus::Deuda)
            ->and($service->client->fresh()->account_status)->toBe(AccountStatus::Deuda);
    });

    it('por vencer si vence dentro de la ventana de aviso (3 días por defecto)', function () {
        $service = makeService();
        makeInvoice(['service' => $service, 'due_date' => today()->addDays(3)->toDateString()]);

        expect(app(SyncAccountStatus::class)->forClient($service->client))->toBe(AccountStatus::PorVencer);
    });

    it('al día si lo pendiente vence más adelante, o si no hay nada pendiente', function () {
        $service = makeService();
        makeInvoice(['service' => $service, 'due_date' => today()->addDays(4)->toDateString()]);

        expect(app(SyncAccountStatus::class)->forClient($service->client))->toBe(AccountStatus::AlDia);
        expect(app(SyncAccountStatus::class)->forClient(Client::factory()->create(['account_status' => 'deuda'])))->toBe(AccountStatus::AlDia);
    });

    it('la mora gana sobre el próximo vencimiento y se ignoran cobros pagados o anulados', function () {
        $service = makeService();
        makeInvoice(['service' => $service, 'due_date' => today()->subDays(9)->toDateString(), 'status' => 'pagada']);
        makeInvoice(['service' => $service, 'due_date' => today()->subDays(9)->toDateString(), 'status' => 'anulada']);
        makeInvoice(['service' => $service, 'due_date' => today()->addDay()->toDateString()]);

        expect(app(SyncAccountStatus::class)->forClient($service->client))->toBe(AccountStatus::PorVencer);

        makeInvoice(['service' => $service, 'due_date' => today()->subDay()->toDateString()]);
        expect(app(SyncAccountStatus::class)->forClient($service->client->fresh()))->toBe(AccountStatus::Deuda);
    });

    it('la ventana sale de la configuración del sysadmin', function () {
        Settings::set('dunning.reminder_before_days', '7');
        $service = makeService();
        makeInvoice(['service' => $service, 'due_date' => today()->addDays(6)->toDateString()]);

        expect(app(SyncAccountStatus::class)->forClient($service->client))->toBe(AccountStatus::PorVencer);
    });

    it('el estado de cuenta ya no se edita a mano desde la ficha', function () {
        $client = Client::factory()->create(['business_name' => 'Cliente X', 'account_status' => 'al_dia']);

        $this->actingAs(userWithRole('dueno'))->put("/clientes/{$client->id}", ['business_name' => 'Cliente X', 'account_status' => 'deuda'])->assertRedirect();

        expect($client->fresh()->account_status)->toBe(AccountStatus::AlDia);
    });
});

describe('recordatorios de pago', function () {
    beforeEach(fn () => Mail::fake());

    it('avisa antes del vencimiento, una sola vez', function () {
        $invoice = makeInvoice(['due_date' => today()->addDays(2)->toDateString()]);

        $this->artisan('finance:send-reminders')->expectsOutputToContain('antes: 1')->assertSuccessful();
        $this->artisan('finance:send-reminders')->expectsOutputToContain('antes: 0')->assertSuccessful();

        Mail::assertQueued(PaymentReminderMail::class, 1);
        Mail::assertQueued(PaymentReminderMail::class, fn ($m) => $m->type === 'antes' && $m->hasTo($invoice->client->email));
        expect(ReminderLog::where('invoice_id', $invoice->id)->where('type', 'antes')->count())->toBe(1);
    });

    it('avisa la mora a partir del día siguiente al vencimiento, una sola vez', function () {
        $hoy = makeInvoice(['due_date' => today()->toDateString()]);
        $ayer = makeInvoice(['due_date' => today()->subDay()->toDateString()]);

        $this->artisan('finance:send-reminders')->assertSuccessful();
        $this->artisan('finance:send-reminders')->assertSuccessful();

        Mail::assertQueued(PaymentReminderMail::class, fn ($m) => $m->type === 'mora' && $m->invoice->is($ayer));
        Mail::assertNotQueued(PaymentReminderMail::class, fn ($m) => $m->type === 'mora' && $m->invoice->is($hoy));
        expect(ReminderLog::where('type', 'mora')->count())->toBe(1);
    });

    it('un cobro con mora recibe primero el aviso previo y después el de mora, sin repetirlos', function () {
        $invoice = makeInvoice(['due_date' => today()->addDay()->toDateString()]);

        $this->artisan('finance:send-reminders')->assertSuccessful();
        $this->travel(2)->days();
        $this->artisan('finance:send-reminders')->assertSuccessful();
        $this->artisan('finance:send-reminders')->assertSuccessful();

        expect(ReminderLog::where('invoice_id', $invoice->id)->pluck('type')->sort()->values()->all())->toBe(['antes', 'mora']);
        Mail::assertQueued(PaymentReminderMail::class, 2);
    });

    it('no avisa lo pagado, anulado ni lejano, ni a clientes inactivos', function () {
        makeInvoice(['due_date' => today()->addDay()->toDateString(), 'status' => 'pagada']);
        makeInvoice(['due_date' => today()->addDay()->toDateString(), 'status' => 'anulada']);
        makeInvoice(['due_date' => today()->addDays(20)->toDateString()]);
        $inactive = makeService(client: Client::factory()->create(['is_active' => false, 'email' => 'baja@x.com']));
        makeInvoice(['service' => $inactive, 'due_date' => today()->addDay()->toDateString()]);

        $this->artisan('finance:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    });

    it('si el cliente no tiene email lo cuenta y reintenta cuando lo cargan', function () {
        $service = makeService(client: Client::factory()->create(['email' => null]));
        $invoice = makeInvoice(['service' => $service, 'due_date' => today()->addDay()->toDateString()]);

        $this->artisan('finance:send-reminders')->expectsOutputToContain('sin email: 1')->assertSuccessful();
        Mail::assertNothingQueued();
        expect(ReminderLog::count())->toBe(0);

        $service->client->update(['email' => 'recien@cargado.com']);
        $this->artisan('finance:send-reminders')->assertSuccessful();

        Mail::assertQueued(PaymentReminderMail::class, fn ($m) => $m->hasTo('recien@cargado.com') && $m->invoice->is($invoice));
    });

    it('respeta los días configurados', function () {
        Settings::set('dunning.reminder_before_days', '1');
        makeInvoice(['due_date' => today()->addDays(2)->toDateString()]);
        $this->artisan('finance:send-reminders')->assertSuccessful();
        Mail::assertNothingQueued();

        Settings::set('dunning.reminder_after_days', '3');
        makeInvoice(['due_date' => today()->subDays(2)->toDateString()]);
        $this->artisan('finance:send-reminders')->assertSuccessful();
        Mail::assertNothingQueued();

        makeInvoice(['due_date' => today()->subDays(3)->toDateString()]);
        $this->artisan('finance:send-reminders')->assertSuccessful();
        Mail::assertQueued(PaymentReminderMail::class, 1);
    });

    it('el correo muestra el importe, el vencimiento y los datos bancarios', function () {
        Settings::set('bank.alias', 'laia.estudio.mp');
        $invoice = makeInvoice(['due_date' => today()->addDays(2)->toDateString()]);

        $mail = new PaymentReminderMail($invoice, 'antes');
        $mail->assertSeeInHtml('$ 280.000,00');
        $mail->assertSeeInHtml('laia.estudio.mp');
        $mail->assertSeeInHtml($invoice->number);
        expect($mail->envelope()->subject)->toContain('Recordatorio de pago');

        $overdue = new PaymentReminderMail($invoice, 'mora');
        $overdue->assertSeeInHtml('venció');
        expect($overdue->envelope()->subject)->toContain('vencido');
    });
});
