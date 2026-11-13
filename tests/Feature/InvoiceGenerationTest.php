<?php

use App\Actions\Finance\GenerateInvoices;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Settings;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

function at(string $date): void
{
    Carbon::setTestNow(Carbon::parse($date.' 10:00', 'UTC'));
}

describe('servicios mensuales', function () {
    it('emite el cobro del mes cuando entra en la ventana de anticipación (10 días por defecto)', function () {
        at('2026-11-10');
        $service = makeService(['due_day' => 20, 'started_at' => '2026-09-01']);

        expect(app(GenerateInvoices::class)->forService($service))->toBe(1);

        $invoice = Invoice::firstOrFail();
        expect($invoice->period)->toBe('2026-11')
            ->and($invoice->due_date->toDateString())->toBe('2026-11-20')
            ->and($invoice->amount)->toBe(28_000_000)
            ->and($invoice->description)->toBe('Community Management — Noviembre 2026')
            ->and($invoice->status->value)->toBe('pendiente')
            ->and($invoice->number)->toMatch('/^C-\d{4}-0001$/');
    });

    it('no emite antes de que se abra la ventana', function () {
        at('2026-11-09');

        expect(app(GenerateInvoices::class)->forService(makeService(['due_day' => 20, 'started_at' => '2026-09-01'])))->toBe(0);
        expect(Invoice::count())->toBe(0);
    });

    it('es idempotente: correrlo dos veces no duplica el cobro', function () {
        at('2026-11-15');
        $service = makeService(['started_at' => '2026-09-01']);

        $first = app(GenerateInvoices::class)->forService($service);
        $second = app(GenerateInvoices::class)->forService($service);

        expect($first)->toBe(1)->and($second)->toBe(0)->and(Invoice::count())->toBe(1);
    });

    it('a fin de mes también emite el cobro del mes siguiente', function () {
        at('2026-11-26');
        $service = makeService(['due_day' => 5, 'started_at' => '2026-09-01']);

        app(GenerateInvoices::class)->forService($service);

        // Noviembre (vence el 5, ya pasó) y diciembre (vence el 5, ventana abierta desde el 25/11).
        expect(Invoice::orderBy('period')->pluck('period')->all())->toBe(['2026-11', '2026-12']);
    });

    it('no cobra períodos anteriores al inicio del servicio', function () {
        at('2026-11-13');
        // Servicio que arranca hoy con vencimiento el día 5: el 5/11 ya pasó (no existía) y el 5/12 todavía no entra en ventana.
        $service = makeService(['due_day' => 5, 'started_at' => '2026-11-13']);

        expect(app(GenerateInvoices::class)->forService($service))->toBe(0);
    });

    it('ajusta el día de vencimiento a los meses cortos', function () {
        at('2027-02-20');
        $service = makeService(['due_day' => 28, 'started_at' => '2026-09-01']);

        app(GenerateInvoices::class)->forService($service);

        expect(Invoice::where('period', '2027-02')->first()->due_date->toDateString())->toBe('2027-02-28');
    });

    it('respeta la anticipación configurada por el sysadmin', function () {
        at('2026-11-17');
        Settings::set('finance.invoice_lead_days', '2');
        $service = makeService(['due_day' => 20, 'started_at' => '2026-09-01']);

        expect(app(GenerateInvoices::class)->forService($service))->toBe(0);

        at('2026-11-18');
        expect(app(GenerateInvoices::class)->forService($service))->toBe(1);
    });
});

describe('pagos únicos y exclusiones', function () {
    it('emite un único cobro con vencimiento en la fecha de inicio, una sola vez', function () {
        at('2026-11-13');
        $service = makeService(['frequency' => 'unico', 'name' => 'Identidad visual', 'started_at' => '2026-12-17']);

        expect(app(GenerateInvoices::class)->forService($service))->toBe(1);
        expect(app(GenerateInvoices::class)->forService($service))->toBe(0);

        $invoice = Invoice::firstOrFail();
        expect($invoice->period)->toBe('unico')->and($invoice->due_date->toDateString())->toBe('2026-12-17')->and($invoice->description)->toBe('Identidad visual');
    });

    it('ignora servicios pausados o cancelados y clientes inactivos', function () {
        at('2026-11-15');
        $paused = makeService(['status' => 'pausado']);
        $cancelled = makeService(['status' => 'cancelado']);
        $inactive = makeService(client: Client::factory()->create(['is_active' => false]));

        expect(app(GenerateInvoices::class)->forAll())->toBe(0)->and(Invoice::count())->toBe(0);
        expect($paused->fresh()->status)->toBe('pausado')->and($cancelled->id)->not->toBe($inactive->id);
    });

    it('forAll genera para todos y actualiza el semáforo de cada cliente', function () {
        at('2026-11-25');
        $a = makeService(['due_day' => 20, 'started_at' => '2026-09-01']);   // 20/11 ya venció → deuda
        makeService(['due_day' => 28, 'started_at' => '2026-09-01']);

        $created = app(GenerateInvoices::class)->forAll();

        expect($created)->toBeGreaterThanOrEqual(2)->and($a->client->fresh()->account_status->value)->toBe('deuda');
    });

    it('el comando programado corre sin errores', function () {
        at('2026-11-15');
        makeService(['started_at' => '2026-09-01']);

        $this->artisan('finance:generate-invoices')->expectsOutputToContain('Cobros creados: 1')->assertSuccessful();
    });

    it('está agendado a diario junto con los recordatorios', function () {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');

        expect($commands)->toContain('finance:generate-invoices')->toContain('finance:send-reminders');
    });
});
