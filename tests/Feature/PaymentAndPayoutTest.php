<?php

use App\Actions\Finance\MarkInvoicePaid;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\TeamPayout;
use App\Notifications\PayoutGenerated;
use App\Notifications\PayoutSettled;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->camila = userWithRole('empleado');
    $this->tomas = userWithRole('empleado');
});

function payWith($test, Invoice $invoice, array $data = [], $as = null)
{
    return $test->actingAs($as ?? $test->duena)->post("/cobros/{$invoice->id}/pagar", array_replace(['payment_method' => 'transferencia'], $data));
}

describe('registrar un pago', function () {
    it('marca el cobro como pagado con método y referencia', function () {
        $invoice = makeInvoice();

        payWith($this, $invoice, ['payment_reference' => 'TRF-9921'])->assertRedirect();

        $fresh = $invoice->fresh();
        expect($fresh->status)->toBe(InvoiceStatus::Pagada)
            ->and($fresh->paid_at)->not->toBeNull()
            ->and($fresh->payment_method->value)->toBe('transferencia')
            ->and($fresh->payment_reference)->toBe('TRF-9921');
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'description' => 'Cobro registrado como pagado']);
    });

    it('genera la liquidación pendiente del empleado con porcentaje', function () {
        $service = makeService(['assigned_user_id' => $this->camila->id, 'split_rule' => 'porcentaje', 'split_percent' => 30]);
        $invoice = makeInvoice(['service' => $service]);

        payWith($this, $invoice)->assertSessionHas('success');

        $payout = TeamPayout::firstOrFail();
        expect($payout->user_id)->toBe($this->camila->id)
            ->and($payout->amount)->toBe(8_400_000)                 // 30 % de $ 280.000,00
            ->and($payout->status)->toBe('pendiente')
            ->and($payout->calculation)->toBe('30 % de $ 280.000,00')
            ->and($payout->invoice_id)->toBe($invoice->id)
            ->and($payout->currency->value)->toBe('ARS');
    });

    it('con monto fijo liquida ese monto, con tope en lo cobrado', function () {
        $fixed = makeService(['assigned_user_id' => $this->camila->id, 'split_rule' => 'monto_fijo', 'split_amount' => 2_500_000]);
        payWith($this, makeInvoice(['service' => $fixed]));
        expect(TeamPayout::first()->amount)->toBe(2_500_000)->and(TeamPayout::first()->calculation)->toBe('Monto fijo de $ 25.000,00');

        // Si el cobro es menor que el fijo, nunca se liquida más de lo cobrado.
        $small = makeService(['assigned_user_id' => $this->tomas->id, 'split_rule' => 'monto_fijo', 'split_amount' => 2_500_000, 'amount' => 1_000_000]);
        payWith($this, makeInvoice(['service' => $small]));
        $capped = TeamPayout::where('user_id', $this->tomas->id)->first();
        expect($capped->amount)->toBe(1_000_000)->and($capped->calculation)->toContain('tope');
    });

    it('con 100 % agencia o sin empleado no genera liquidación', function () {
        payWith($this, makeInvoice(['service' => makeService(['assigned_user_id' => $this->camila->id, 'split_rule' => '100_agencia'])]));
        payWith($this, makeInvoice(['service' => makeService()]));

        expect(TeamPayout::count())->toBe(0);
    });

    it('avisa al empleado con una notificación', function () {
        $service = makeService(['assigned_user_id' => $this->camila->id, 'split_rule' => 'porcentaje', 'split_percent' => 50]);

        payWith($this, makeInvoice(['service' => $service]));

        $n = $this->camila->notifications()->first();
        expect($n->type)->toBe(PayoutGenerated::class)->and($n->data['body'])->toContain('$ 140.000,00')->and($n->data['url'])->toBe('/liquidaciones');
    });

    it('un cobro no se puede cobrar dos veces ni genera dos liquidaciones', function () {
        $service = makeService(['assigned_user_id' => $this->camila->id, 'split_rule' => 'porcentaje', 'split_percent' => 30]);
        $invoice = makeInvoice(['service' => $service]);

        payWith($this, $invoice)->assertSessionHasNoErrors();
        payWith($this, $invoice)->assertSessionHasErrors('invoice');

        expect(TeamPayout::count())->toBe(1)->and($this->camila->notifications()->count())->toBe(1);
    });

    it('no se cobra un cobro anulado', function () {
        $invoice = makeInvoice(['status' => 'anulada']);

        expect(fn () => app(MarkInvoicePaid::class)->handle($invoice, $this->duena))->toThrow(ValidationException::class);
        expect(TeamPayout::count())->toBe(0);
    });

    it('actualiza el semáforo del cliente al pagar', function () {
        $service = makeService();
        $invoice = makeInvoice(['service' => $service, 'due_date' => today()->subDays(3)->toDateString()]);
        app(\App\Actions\Finance\SyncAccountStatus::class)->forClient($service->client);
        expect($service->client->fresh()->account_status->value)->toBe('deuda');

        payWith($this, $invoice);

        expect($service->client->fresh()->account_status->value)->toBe('al_dia');
    });

    it('valida método, fecha futura y permisos', function () {
        $invoice = makeInvoice();

        payWith($this, $invoice, ['payment_method' => 'trueque'])->assertSessionHasErrors('payment_method');
        payWith($this, $invoice, ['paid_at' => today()->addDay()->toDateString()])->assertSessionHasErrors('paid_at');
        payWith($this, $invoice, [], $this->camila)->assertForbidden();

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pendiente);
    });

    it('acepta una fecha de pago pasada', function () {
        $invoice = makeInvoice();

        payWith($this, $invoice, ['paid_at' => today()->subDays(4)->toDateString()])->assertRedirect();

        expect($invoice->fresh()->paid_at->toDateString())->toBe(today()->subDays(4)->toDateString());
    });
});

describe('anular un cobro', function () {
    it('anula un cobro pendiente con motivo y recalcula el semáforo', function () {
        $service = makeService();
        $invoice = makeInvoice(['service' => $service, 'due_date' => today()->subDay()->toDateString()]);
        app(\App\Actions\Finance\SyncAccountStatus::class)->forClient($service->client);

        $this->actingAs($this->duena)->post("/cobros/{$invoice->id}/anular", ['reason' => 'Servicio dado de baja'])->assertRedirect();

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Anulada)->and($invoice->fresh()->void_reason)->toBe('Servicio dado de baja')
            ->and($service->client->fresh()->account_status->value)->toBe('al_dia');
    });

    it('no anula un cobro pagado y exige motivo', function () {
        $paid = makeInvoice();
        payWith($this, $paid);

        $this->actingAs($this->duena)->post("/cobros/{$paid->id}/anular", ['reason' => 'x'])->assertSessionHasErrors('invoice');
        $this->actingAs($this->duena)->post('/cobros/'.makeInvoice()->id.'/anular', [])->assertSessionHasErrors('reason');
        $this->actingAs($this->camila)->post("/cobros/{$paid->id}/anular", ['reason' => 'x'])->assertForbidden();
    });
});

describe('liquidaciones', function () {
    beforeEach(function () {
        $this->svcCamila = makeService(['assigned_user_id' => $this->camila->id, 'split_rule' => 'porcentaje', 'split_percent' => 30]);
        $this->svcTomas = makeService(['assigned_user_id' => $this->tomas->id, 'split_rule' => 'porcentaje', 'split_percent' => 20]);
        payWith($this, makeInvoice(['service' => $this->svcCamila]));
        payWith($this, makeInvoice(['service' => $this->svcTomas]));
    });

    it('el empleado ve solo las suyas', function () {
        // Se valida sobre los datos de la página (no sobre el HTML, que puede incluir avisos de sesión).
        $this->actingAs($this->camila)->get('/liquidaciones')->assertOk()->assertInertia(fn ($p) => $p
            ->where('scope', 'own')
            ->has('payouts.data', 1)
            ->where('payouts.data.0.amount_label', '$ 84.000,00')        // 30 % de 280.000 (lo de Tomás sería 56.000)
            ->where('payouts.data.0.user_id', $this->camila->id)
            ->where('totals.pending.0', '$ 84.000,00')
            ->where('employees', []));                                   // ni siquiera ve la lista de otros empleados
    });

    it('Dirección ve todas y puede filtrar por empleado', function () {
        $this->actingAs($this->duena)->get('/liquidaciones')->assertInertia(fn ($p) => $p->where('scope', 'all')->has('payouts.data', 2));
        $this->actingAs($this->duena)->get("/liquidaciones?user={$this->tomas->id}")->assertInertia(fn ($p) => $p->has('payouts.data', 1));
    });

    it('un empleado no puede forzar el filtro para ver las de otro', function () {
        $this->actingAs($this->camila)->get("/liquidaciones?user={$this->tomas->id}")->assertInertia(fn ($p) => $p->has('payouts.data', 1)
            ->where('payouts.data.0.user_id', $this->camila->id));
    });

    it('sin permisos de liquidaciones no entra', function () {
        $sinPermiso = userWithRole('empleado');
        \Spatie\Permission\Models\Role::findByName('empleado')->revokePermissionTo('payouts.view_own');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($sinPermiso->fresh())->get('/liquidaciones')->assertForbidden();
    });

    it('Dirección marca liquidadas y el empleado recibe aviso', function () {
        $payout = TeamPayout::where('user_id', $this->camila->id)->first();

        $this->actingAs($this->duena)->post('/liquidaciones/liquidar', ['ids' => [$payout->id], 'note' => 'Transferencia 14/11'])->assertRedirect();

        $fresh = $payout->fresh();
        expect($fresh->status)->toBe('liquidada')->and($fresh->settled_at)->not->toBeNull()
            ->and($fresh->settled_by)->toBe($this->duena->id)->and($fresh->settlement_note)->toBe('Transferencia 14/11');
        expect($this->camila->notifications()->where('type', PayoutSettled::class)->count())->toBe(1);
    });

    it('no se liquida dos veces y no se liquida a medias', function () {
        $a = TeamPayout::where('user_id', $this->camila->id)->first();
        $b = TeamPayout::where('user_id', $this->tomas->id)->first();
        $this->actingAs($this->duena)->post('/liquidaciones/liquidar', ['ids' => [$a->id]]);

        $this->actingAs($this->duena)->post('/liquidaciones/liquidar', ['ids' => [$a->id, $b->id]])->assertSessionHasErrors('ids');

        expect($b->fresh()->status)->toBe('pendiente');   // la transacción se revirtió completa
    });

    it('el empleado no puede liquidar', function () {
        $payout = TeamPayout::first();

        $this->actingAs($this->camila)->post('/liquidaciones/liquidar', ['ids' => [$payout->id]])->assertForbidden();

        expect($payout->fresh()->status)->toBe('pendiente');
    });
});
