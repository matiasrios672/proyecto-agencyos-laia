<?php

use App\Actions\Finance\MarkInvoicePaid;
use App\Enums\Currency;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Services\FinanceReport;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->admin = userWithRole('sysadmin');
    $this->camila = userWithRole('empleado');
});

describe('acceso', function () {
    it('el empleado no entra a finanzas, cobros, egresos ni comunicados', function () {
        foreach (['/finanzas', '/cobros', '/egresos', '/comunicados'] as $url) {
            $this->actingAs($this->camila)->get($url)->assertForbidden();
        }
        $this->actingAs($this->camila)->post('/cobros', [])->assertForbidden();
        $this->actingAs($this->camila)->post('/cobros/generar')->assertForbidden();
        $this->actingAs($this->camila)->post('/egresos', [])->assertForbidden();
        $this->actingAs($this->camila)->post('/comunicados', [])->assertForbidden();
    });

    it('Dirección y sysadmin sí', function (string $who) {
        foreach (['/finanzas', '/cobros', '/egresos', '/comunicados', '/liquidaciones'] as $url) {
            $this->actingAs($this->$who)->get($url)->assertOk();
        }
    })->with(['duena', 'admin']);

    it('un permiso de solo lectura no alcanza para modificar', function () {
        $lector = userWithRole('empleado');
        $lector->givePermissionTo('finance.view');
        $invoice = makeInvoice();

        $this->actingAs($lector->fresh())->get('/cobros')->assertOk();
        $this->actingAs($lector->fresh())->post("/cobros/{$invoice->id}/pagar", ['payment_method' => 'efectivo'])->assertForbidden();
        $this->actingAs($lector->fresh())->post('/egresos', ['name' => 'x'])->assertForbidden();
    });
});

describe('cobros', function () {
    it('lista y filtra por estado y búsqueda', function () {
        $pendiente = makeInvoice(['description' => 'Pendiente vigente']);
        $vencido = makeInvoice(['description' => 'Cobro vencido', 'due_date' => today()->subDays(4)->toDateString()]);
        $pagado = makeInvoice(['description' => 'Cobro pagado']);
        app(MarkInvoicePaid::class)->handle($pagado, $this->duena, ['payment_method' => 'efectivo']);

        $this->actingAs($this->duena)->get('/cobros?status=vencida')->assertSee('Cobro vencido')->assertDontSee('Pendiente vigente')->assertDontSee('Cobro pagado');
        $this->actingAs($this->duena)->get('/cobros?status=pendiente')->assertSee('Pendiente vigente')->assertDontSee('Cobro vencido');
        $this->actingAs($this->duena)->get('/cobros?status=pagada')->assertSee('Cobro pagado')->assertDontSee('Pendiente vigente');
        $this->actingAs($this->duena)->get('/cobros?q=vigente')->assertSee('Pendiente vigente')->assertDontSee('Cobro pagado');
        expect($pendiente->number)->not->toBe($vencido->number);
    });

    it('crea un cobro manual y actualiza el semáforo', function () {
        $client = Client::factory()->create(['trade_name' => null]);

        $this->actingAs($this->duena)->post('/cobros', [
            'client_id' => $client->id, 'description' => 'Sesión de fotos extra', 'amount' => '45000.50',
            'currency' => 'ARS', 'due_date' => today()->subDay()->toDateString(),
        ])->assertRedirect();

        $invoice = Invoice::firstOrFail();
        expect($invoice->amount)->toBe(4_500_050)->and($invoice->client_service_id)->toBeNull()->and($invoice->created_by)->toBe($this->duena->id)
            ->and($client->fresh()->account_status->value)->toBe('deuda');
    });

    it('valida el cobro manual', function () {
        $this->actingAs($this->duena)->post('/cobros', ['client_id' => 9999, 'description' => '', 'amount' => 0, 'currency' => 'EUR', 'due_date' => 'ayer'])
            ->assertSessionHasErrors(['client_id', 'description', 'amount', 'currency', 'due_date']);
    });

    it('"generar cobros" emite los que faltan sin duplicar', function () {
        makeService(['due_day' => today()->addDays(5)->day, 'started_at' => today()->subMonths(2)->toDateString()]);

        $this->actingAs($this->duena)->post('/cobros/generar')->assertRedirect()->assertSessionHas('success');
        $this->actingAs($this->duena)->post('/cobros/generar')->assertSessionHas('success', 'No había cobros nuevos para generar.');

        expect(Invoice::count())->toBeGreaterThanOrEqual(1);
    });

    it('la ficha del cliente muestra los cobros solo a quien ve finanzas', function () {
        $service = makeService(['assigned_user_id' => $this->camila->id]);
        $service->client->users()->attach($this->camila);
        makeInvoice(['service' => $service, 'description' => 'Cobro visible solo a Direccion']);

        $this->actingAs($this->duena)->get("/clientes/{$service->client_id}")->assertInertia(fn ($p) => $p->has('invoices', 1)->where('invoices.0.description', 'Cobro visible solo a Direccion'));
        $this->actingAs($this->camila)->get("/clientes/{$service->client_id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('invoices', [])->where('serviceOptions', null))
            ->assertDontSee('Cobro visible solo a Direccion');
    });
});

describe('egresos', function () {
    it('calcula el equivalente mensual y el costo por mes', function () {
        $mensual = new Expense(['amount' => 12_000, 'frequency' => 'mensual', 'starts_on' => '2026-01-01', 'is_active' => true]);
        $anual = new Expense(['amount' => 12_000, 'frequency' => 'anual', 'starts_on' => '2026-01-01', 'is_active' => true]);
        $unico = new Expense(['amount' => 5_000, 'frequency' => 'unico', 'starts_on' => '2026-03-10', 'is_active' => true]);
        $terminado = new Expense(['amount' => 7_000, 'frequency' => 'mensual', 'starts_on' => '2026-01-01', 'ends_on' => '2026-02-28', 'is_active' => true]);
        $pausado = new Expense(['amount' => 7_000, 'frequency' => 'mensual', 'starts_on' => '2026-01-01', 'is_active' => false]);
        $marzo = now()->setDate(2026, 3, 15);

        expect($mensual->costInMonth($marzo))->toBe(12_000)
            ->and($anual->costInMonth($marzo))->toBe(1_000)
            ->and($unico->costInMonth($marzo))->toBe(5_000)->and($unico->costInMonth($marzo->copy()->addMonth()))->toBe(0)
            ->and($terminado->costInMonth($marzo))->toBe(0)->and($terminado->costInMonth($marzo->copy()->subMonth()))->toBe(7_000)
            ->and($pausado->costInMonth($marzo))->toBe(0)
            ->and($mensual->costInMonth(now()->setDate(2025, 12, 1)))->toBe(0);   // todavía no había empezado
    });

    it('Dirección da de alta, edita y borra egresos', function () {
        $this->actingAs($this->duena)->post('/egresos', [
            'name' => 'Adobe Creative Cloud', 'category' => 'software', 'amount' => '59.99', 'currency' => 'USD',
            'frequency' => 'mensual', 'starts_on' => '2026-01-01',
        ])->assertRedirect();

        $expense = Expense::firstOrFail();
        expect($expense->amount)->toBe(5_999)->and($expense->currency)->toBe(Currency::USD);

        $this->actingAs($this->duena)->put("/egresos/{$expense->id}", [
            'name' => 'Adobe CC', 'category' => 'software', 'amount' => '64', 'currency' => 'USD',
            'frequency' => 'mensual', 'starts_on' => '2026-01-01', 'is_active' => false,
        ])->assertRedirect();
        expect($expense->fresh()->name)->toBe('Adobe CC')->and($expense->fresh()->is_active)->toBeFalse();

        $this->actingAs($this->duena)->delete("/egresos/{$expense->id}")->assertRedirect();
        expect(Expense::count())->toBe(0);
    });

    it('valida categoría, monto y fechas', function () {
        $this->actingAs($this->duena)->post('/egresos', [
            'name' => '', 'category' => 'lujos', 'amount' => -5, 'currency' => 'USD', 'frequency' => 'cuando-quiera',
            'starts_on' => '2026-05-01', 'ends_on' => '2026-01-01',
        ])->assertSessionHasErrors(['name', 'category', 'amount', 'frequency', 'ends_on']);
    });
});

describe('Ganancia Neta Real y MRR', function () {
    it('neta = cobrado − liquidaciones al equipo − egresos del mes, por moneda', function () {
        $service = makeService(['assigned_user_id' => $this->camila->id, 'split_rule' => 'porcentaje', 'split_percent' => 25]);   // $ 280.000
        app(MarkInvoicePaid::class)->handle(makeInvoice(['service' => $service]), $this->duena, ['payment_method' => 'transferencia']);

        $usd = makeService(['amount' => 100_000, 'currency' => 'USD']);   // US$ 1.000
        app(MarkInvoicePaid::class)->handle(makeInvoice(['service' => $usd]), $this->duena, ['payment_method' => 'transferencia']);

        Expense::create(['name' => 'Coworking', 'category' => 'oficina', 'amount' => 9_000_000, 'currency' => 'ARS', 'frequency' => 'mensual', 'starts_on' => now()->startOfYear(), 'is_active' => true]);
        Expense::create(['name' => 'Canva', 'category' => 'software', 'amount' => 12_000, 'currency' => 'USD', 'frequency' => 'anual', 'starts_on' => now()->startOfYear(), 'is_active' => true]);

        $overview = app(FinanceReport::class)->overview();

        // ARS: 280.000 cobrado − 70.000 (25 %) − 90.000 coworking = 120.000
        expect($overview['ARS']['collected'])->toBe(28_000_000)->and($overview['ARS']['payouts'])->toBe(7_000_000)
            ->and($overview['ARS']['expenses'])->toBe(9_000_000)->and($overview['ARS']['net'])->toBe(12_000_000)
            ->and($overview['ARS']['payouts_pending'])->toBe(7_000_000);
        // USD: 1.000 cobrado − 10 (Canva 120/12) = 990
        expect($overview['USD']['collected'])->toBe(100_000)->and($overview['USD']['expenses'])->toBe(1_000)->and($overview['USD']['net'])->toBe(99_000);
    });

    it('el MRR suma solo servicios mensuales activos, por moneda', function () {
        makeService(['amount' => 28_000_000]);
        makeService(['amount' => 9_000_000]);
        makeService(['amount' => 50_000, 'currency' => 'USD']);
        makeService(['amount' => 99_999_999, 'frequency' => 'unico']);
        makeService(['amount' => 99_999_999, 'status' => 'pausado']);

        $overview = app(FinanceReport::class)->overview();

        expect($overview['ARS']['mrr'])->toBe(37_000_000)->and($overview['USD']['mrr'])->toBe(50_000);
    });

    it('separa pendiente y en mora, y no cuenta lo anulado', function () {
        makeInvoice(['amount' => 1_000_000]);
        makeInvoice(['amount' => 2_000_000, 'due_date' => today()->subDay()->toDateString()]);
        makeInvoice(['amount' => 9_000_000, 'status' => 'anulada']);

        $o = app(FinanceReport::class)->overview()['ARS'];

        expect($o['pending'])->toBe(3_000_000)->and($o['overdue'])->toBe(2_000_000);
    });

    it('el gráfico trae 6 meses por moneda', function () {
        $series = app(FinanceReport::class)->series(6);

        expect($series['ARS'])->toHaveCount(6)->and($series['USD'])->toHaveCount(6)->and($series['ARS'][5]['month'])->toBe(now()->format('Y-m'));
    });

    it('consolida en pesos solo con cotización configurada', function () {
        $this->actingAs($this->duena)->get('/finanzas')->assertInertia(fn ($p) => $p->where('consolidated', null)->where('rate', null));

        \App\Support\Settings::set('finance.usd_ars_rate', '1200');
        makeService(['amount' => 10_000, 'currency' => 'USD']);   // US$ 100/mes

        $this->actingAs($this->duena)->get('/finanzas')->assertInertia(fn ($p) => $p->where('rate', 1200)->where('consolidated.mrr.cents', 12_000_000));
    });
});

describe('comunicados', function () {
    beforeEach(function () {
        Mail::fake();
        $this->conEmail = Client::factory()->create(['email' => 'a@cliente.com', 'trade_name' => null, 'account_status' => 'deuda']);
        $this->otroConEmail = Client::factory()->create(['email' => 'b@cliente.com', 'trade_name' => null, 'account_status' => 'al_dia']);
        $this->sinEmail = Client::factory()->create(['email' => null, 'trade_name' => null, 'account_status' => 'deuda']);
        $this->inactivo = Client::factory()->create(['email' => 'baja@cliente.com', 'is_active' => false]);
    });

    it('guarda un borrador sin enviar nada', function () {
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => 'Aviso', 'body' => 'Hola', 'audience' => 'todos'])->assertRedirect();

        expect(Announcement::firstOrFail()->status)->toBe('borrador');
        Mail::assertNothingQueued();
    });

    it('envía a todos los clientes activos con email y registra a los que no tienen', function () {
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => 'Feriado', 'body' => "Línea 1\nLínea 2", 'audience' => 'todos', 'send' => true])
            ->assertRedirect()->assertSessionHas('success');

        Mail::assertQueued(AnnouncementMail::class, 2);
        Mail::assertQueued(AnnouncementMail::class, fn ($m) => $m->hasTo('a@cliente.com'));
        Mail::assertNotQueued(AnnouncementMail::class, fn ($m) => $m->hasTo('baja@cliente.com'));

        $a = Announcement::firstOrFail();
        expect($a->status)->toBe('enviado')->and($a->sent_at)->not->toBeNull()
            ->and($a->recipients()->where('status', 'enviado')->count())->toBe(2)
            ->and($a->recipients()->where('status', 'sin_email')->count())->toBe(1);
    });

    it('filtra por estado de cuenta', function () {
        $this->actingAs($this->duena)->post('/comunicados', [
            'subject' => 'Deuda', 'body' => 'Recordatorio', 'audience' => 'estado', 'status_filter' => 'deuda', 'send' => true,
        ])->assertRedirect();

        Mail::assertQueued(AnnouncementMail::class, 1);
        Mail::assertQueued(AnnouncementMail::class, fn ($m) => $m->hasTo('a@cliente.com'));
        expect(Announcement::first()->recipients)->toHaveCount(2);   // el de deuda con email y el de deuda sin email
    });

    it('envía a clientes elegidos', function () {
        $this->actingAs($this->duena)->post('/comunicados', [
            'subject' => 'Solo B', 'body' => 'Hola B', 'audience' => 'clientes', 'client_ids' => [$this->otroConEmail->id], 'send' => true,
        ])->assertRedirect();

        Mail::assertQueued(AnnouncementMail::class, 1);
        Mail::assertQueued(AnnouncementMail::class, fn ($m) => $m->hasTo('b@cliente.com'));
    });

    it('no se envía dos veces', function () {
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => 'Aviso', 'body' => 'Hola', 'audience' => 'todos', 'send' => true]);
        $a = Announcement::firstOrFail();

        $this->actingAs($this->duena)->post("/comunicados/{$a->id}/enviar")->assertSessionHasErrors('announcement');

        Mail::assertQueued(AnnouncementMail::class, 2);
        expect($a->recipients()->count())->toBe(3);
    });

    it('un borrador se puede enviar después y uno enviado no se borra', function () {
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => 'Luego', 'body' => 'Hola', 'audience' => 'todos']);
        $a = Announcement::firstOrFail();

        $this->actingAs($this->duena)->post("/comunicados/{$a->id}/enviar")->assertRedirect();
        Mail::assertQueued(AnnouncementMail::class, 2);

        $this->actingAs($this->duena)->delete("/comunicados/{$a->id}")->assertStatus(422);
        expect(Announcement::count())->toBe(1);
    });

    it('un borrador se elimina', function () {
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => 'x', 'body' => 'y', 'audience' => 'todos']);

        $this->actingAs($this->duena)->delete('/comunicados/'.Announcement::first()->id)->assertRedirect('/comunicados');

        expect(Announcement::count())->toBe(0);
    });

    it('valida audiencia y contenido', function () {
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => '', 'body' => '', 'audience' => 'todos'])->assertSessionHasErrors(['subject', 'body']);
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => 'x', 'body' => 'y', 'audience' => 'estado'])->assertSessionHasErrors('status_filter');
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => 'x', 'body' => 'y', 'audience' => 'clientes'])->assertSessionHasErrors('client_ids');
        $this->actingAs($this->duena)->post('/comunicados', ['subject' => 'x', 'body' => 'y', 'audience' => 'publico'])->assertSessionHasErrors('audience');
    });

    it('rechaza enviar a una audiencia vacía', function () {
        $this->actingAs($this->duena)->post('/comunicados', [
            'subject' => 'x', 'body' => 'y', 'audience' => 'estado', 'status_filter' => 'por_vencer', 'send' => true,
        ])->assertSessionHasErrors('announcement');

        Mail::assertNothingQueued();
    });

    it('el correo escapa el contenido (sin HTML inyectado)', function () {
        $a = Announcement::create(['subject' => 'x', 'body' => '<script>alert(1)</script> Hola', 'audience' => 'todos', 'status' => 'borrador']);

        $html = (new AnnouncementMail($a, 'Cliente'))->render();

        expect($html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
    });
});
