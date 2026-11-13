<?php

use App\Enums\QuoteStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\KanbanBoard;
use App\Notifications\ClientAssigned;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->camila = userWithRole('empleado');
    $this->julian = userWithRole('empleado');
    $this->quote = makeQuote($this->duena, ['prospect_tax_id' => '30-71458963-2']);
    $this->items = $this->quote->items;
});

function convertPayload($test, array $overrides = []): array
{
    return array_replace([
        'start_date' => '2026-12-17',
        'items' => [
            ['quote_item_id' => $test->items[0]->id, 'user_id' => $test->camila->id, 'split_rule' => 'porcentaje', 'split_percent' => 30],
            ['quote_item_id' => $test->items[1]->id, 'user_id' => $test->julian->id, 'split_rule' => 'monto_fijo', 'split_amount' => '100000'],
        ],
    ], $overrides);
}

it('convierte un prospecto en cliente activo con servicios, equipo y tablero', function () {
    $response = $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", convertPayload($this));

    $client = Client::where('business_name', 'Vivero Los Aromos')->firstOrFail();
    $response->assertRedirect("/clientes/{$client->id}");

    expect($client->is_active)->toBeTrue()
        ->and($client->tax_id)->toBe('30-71458963-2')
        ->and($client->email)->toBe('hola@losaromos.com.ar')
        ->and($client->account_status->value)->toBe('al_dia');

    // Servicios contratados con monto de la línea (cantidad × precio), empleado y regla de reparto.
    $services = $client->services()->orderBy('id')->get();
    expect($services)->toHaveCount(2)
        ->and($services[0]->name)->toBe('Community Management')
        ->and($services[0]->amount)->toBe(28_000_000)
        ->and($services[0]->frequency->value)->toBe('mensual')
        ->and($services[0]->assigned_user_id)->toBe($this->camila->id)
        ->and($services[0]->split_rule->value)->toBe('porcentaje')
        ->and((float) $services[0]->split_percent)->toBe(30.0)
        ->and($services[0]->due_day)->toBe(17)
        ->and($services[1]->amount)->toBe(90_000_100)
        ->and($services[1]->split_rule->value)->toBe('monto_fijo')
        ->and($services[1]->split_amount)->toBe(10_000_000);

    // Tablero inicial.
    $board = KanbanBoard::where('client_id', $client->id)->firstOrFail();
    expect($board->columns->pluck('name')->all())->toBe(['Pendientes', 'Listo', 'Publicado']);

    // Presupuesto aprobado y enlazado.
    $quote = $this->quote->fresh();
    expect($quote->status)->toBe(QuoteStatus::Aprobado)
        ->and($quote->converted_client_id)->toBe($client->id)
        ->and($quote->approved_at)->not->toBeNull();

    // Los empleados asignados ven el cliente y reciben aviso.
    expect($client->users()->pluck('users.id')->all())->toContain($this->camila->id, $this->julian->id)
        ->and($this->camila->notifications()->first()->type)->toBe(ClientAssigned::class);
    $this->actingAs($this->camila)->get("/clientes/{$client->id}")->assertOk();

    $this->assertDatabaseHas('activity_log', ['log_name' => 'quotes', 'description' => 'Presupuesto aprobado y convertido en cliente']);
});

it('al convertir queda emitido el primer cobro de cada servicio y el cliente pasa a "por vencer"', function () {
    $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", ['start_date' => today()->toDateString(), 'items' => []])->assertRedirect();

    $client = Client::where('business_name', 'Vivero Los Aromos')->firstOrFail();
    $invoices = $client->invoices()->orderBy('id')->get();

    expect($invoices)->toHaveCount(2)
        ->and($invoices->pluck('period')->all())->toBe([now()->format('Y-m'), 'unico'])
        ->and($invoices->pluck('amount')->all())->toBe([28_000_000, 90_000_100])
        ->and($invoices->every(fn ($i) => $i->status->value === 'pendiente' && $i->due_date->isToday()))->toBeTrue()
        ->and($client->fresh()->account_status->value)->toBe('por_vencer');

    // Volver a generar no duplica nada.
    app(\App\Actions\Finance\GenerateInvoices::class)->forClient($client);
    expect($client->invoices()->count())->toBe(2);
});

it('sin empleado asignado todo queda para la agencia', function () {
    $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", ['items' => []])->assertRedirect();

    $services = Client::where('business_name', 'Vivero Los Aromos')->first()->services;
    expect($services)->toHaveCount(2)
        ->and($services->every(fn ($s) => $s->assigned_user_id === null && $s->split_rule->value === '100_agencia'))->toBeTrue();
});

it('un presupuesto de cliente existente agrega servicios a ese cliente sin duplicarlo', function () {
    $client = Client::factory()->create();
    $quote = makeQuote($this->duena, ['client_id' => $client->id, 'prospect_name' => null]);

    $this->actingAs($this->duena)->post("/presupuestos/{$quote->id}/convertir", [])->assertRedirect("/clientes/{$client->id}");

    expect(Client::count())->toBe(1)->and($client->services()->count())->toBe(2)->and($quote->fresh()->converted_client_id)->toBe($client->id);
});

it('no se puede convertir dos veces', function () {
    $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", [])->assertRedirect();

    $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", [])->assertSessionHasErrors('quote');

    expect(Client::count())->toBe(1)->and(ClientService::count())->toBe(2);
});

it('es atómico: si algo falla no queda nada a medias', function () {
    $payload = convertPayload($this, ['items' => [
        // El segundo ítem es inválido (monto fijo mayor al importe del servicio) y la acción lo rechaza al final.
        ['quote_item_id' => $this->items[0]->id, 'user_id' => $this->camila->id, 'split_rule' => 'porcentaje', 'split_percent' => 10],
        ['quote_item_id' => $this->items[1]->id, 'user_id' => $this->julian->id, 'split_rule' => 'monto_fijo', 'split_amount' => '99999999'],
    ]]);

    $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", $payload)
        ->assertSessionHasErrors('items.1.split_amount');

    expect(Client::count())->toBe(0)
        ->and(ClientService::count())->toBe(0)
        ->and(KanbanBoard::count())->toBe(0)
        ->and($this->quote->fresh()->status)->toBe(QuoteStatus::Borrador)
        ->and($this->camila->notifications()->count())->toBe(0);
});

it('rechaza un CUIT que ya pertenece a otro cliente', function () {
    Client::factory()->create(['tax_id' => '30-71458963-2']);

    $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", [])->assertSessionHasErrors('quote');

    expect(Client::count())->toBe(1)->and($this->quote->fresh()->status)->toBe(QuoteStatus::Borrador);
});

it('valida la regla de reparto y al empleado', function (array $item, string $errorKey) {
    $payload = ['items' => [['quote_item_id' => $this->items[0]->id] + $item]];

    $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", $payload)->assertSessionHasErrors($errorKey);

    expect(Client::count())->toBe(0);
})->with([
    'porcentaje > 100' => [['user_id' => 1, 'split_rule' => 'porcentaje', 'split_percent' => 120], 'items.0.split_percent'],
    'regla inexistente' => [['user_id' => 1, 'split_rule' => 'regalo'], 'items.0.split_rule'],
    'usuario inexistente' => [['user_id' => 99999, 'split_rule' => 'porcentaje', 'split_percent' => 10], 'items.0.user_id'],
]);

it('no asigna a un sysadmin ni a alguien dado de baja', function () {
    $baja = userWithRole('empleado', ['is_active' => false]);
    $admin = userWithRole('sysadmin');

    foreach ([$baja, $admin] as $user) {
        $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", [
            'items' => [['quote_item_id' => $this->items[0]->id, 'user_id' => $user->id, 'split_rule' => 'porcentaje', 'split_percent' => 10]],
        ])->assertSessionHasErrors('items.0.user_id');
    }
    expect(Client::count())->toBe(0);
});

it('no convierte presupuestos vencidos, rechazados ni sin permiso', function () {
    $vencido = makeQuote($this->duena, ['valid_until' => now()->subDay()->toDateString()]);
    $rechazado = makeQuote($this->duena);
    $rechazado->forceFill(['status' => QuoteStatus::Rechazado])->save();

    $this->actingAs($this->duena)->post("/presupuestos/{$vencido->id}/convertir", [])->assertSessionHasErrors('quote');
    $this->actingAs($this->duena)->post("/presupuestos/{$rechazado->id}/convertir", [])->assertSessionHasErrors('quote');
    $this->actingAs($this->camila)->post("/presupuestos/{$this->quote->id}/convertir", [])->assertForbidden();

    expect(Client::count())->toBe(0);
});

it('extender la validez permite convertir un presupuesto vencido', function () {
    $vencido = makeQuote($this->duena, ['valid_until' => now()->subDay()->toDateString()]);

    $this->actingAs($this->duena)->put("/presupuestos/{$vencido->id}", quotePayload(['valid_until' => now()->addWeek()->toDateString()]))->assertRedirect();
    $this->actingAs($this->duena)->post("/presupuestos/{$vencido->id}/convertir", [])->assertRedirect();

    expect($vencido->fresh()->status)->toBe(QuoteStatus::Aprobado);
});

it('el reparto nunca supera lo cobrado', function () {
    $this->actingAs($this->duena)->post("/presupuestos/{$this->quote->id}/convertir", convertPayload($this));
    [$porcentaje, $fijo] = Client::first()->services()->orderBy('id')->get()->all();

    expect($porcentaje->employeeShare(1_000_000))->toBe(300_000)
        ->and($fijo->employeeShare(5_000_000))->toBe(5_000_000)   // tope: no más de lo cobrado
        ->and($fijo->employeeShare(90_000_100))->toBe(10_000_000)
        ->and($porcentaje->employeeShare(0))->toBe(0);
});
