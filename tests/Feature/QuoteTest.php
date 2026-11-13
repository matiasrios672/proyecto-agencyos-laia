<?php

use App\Enums\QuoteStatus;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\Quote;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->admin = userWithRole('sysadmin');
    $this->camila = userWithRole('empleado');
});

describe('acceso', function () {
    it('el empleado no accede a presupuestos ni catálogo', function () {
        foreach (['/presupuestos', '/presupuestos/create', '/catalogo'] as $url) {
            $this->actingAs($this->camila)->get($url)->assertForbidden();
        }
        $this->actingAs($this->camila)->post('/presupuestos', quotePayload())->assertForbidden();
    });

    it('dirección y sysadmin sí', function (string $who) {
        $this->actingAs($this->$who)->get('/presupuestos')->assertOk();
        $this->actingAs($this->$who)->get('/presupuestos/create')->assertOk();
    })->with(['duena', 'admin']);

    it('exige sesión', function () {
        $this->get('/presupuestos')->assertRedirect('/login');
    });
});

describe('creación y totales', function () {
    it('calcula subtotal, descuento y total en centavos', function () {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload())->assertRedirect();

        $quote = Quote::firstOrFail();
        expect($quote->subtotal)->toBe(118_000_100)
            ->and($quote->discount)->toBe(11_800_010)
            ->and($quote->total)->toBe(106_200_090)
            ->and($quote->items)->toHaveCount(2)
            ->and($quote->items[1]->unit_price)->toBe(45_000_050)
            ->and($quote->status)->toBe(QuoteStatus::Borrador)
            ->and($quote->created_by)->toBe($this->duena->id);
    });

    it('numera correlativamente por año', function () {
        $year = now()->year;

        $a = makeQuote($this->duena);
        $b = makeQuote($this->duena);

        expect($a->number)->toBe("P-{$year}-0001")->and($b->number)->toBe("P-{$year}-0002");
    });

    it('un presupuesto eliminado no libera su número', function () {
        $a = makeQuote($this->duena);
        $a->delete();

        expect(makeQuote($this->duena)->number)->toEndWith('-0002');
    });

    it('soporta USD y un descuento de 0', function () {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload(['currency' => 'USD', 'discount_percent' => 0]))->assertRedirect();

        $quote = Quote::firstOrFail();
        expect($quote->currency->value)->toBe('USD')->and($quote->discount)->toBe(0)->and($quote->total)->toBe($quote->subtotal);
    });

    it('con cliente existente descarta los datos de prospecto', function () {
        $client = Client::factory()->create();

        $this->actingAs($this->duena)->post('/presupuestos', quotePayload(['client_id' => $client->id]))->assertRedirect();

        $quote = Quote::firstOrFail();
        expect($quote->client_id)->toBe($client->id)->and($quote->prospect_name)->toBeNull();
    });

    it('guarda una referencia al catálogo pero conserva su propia copia del precio', function () {
        $service = CatalogService::create(['name' => 'SEO', 'price' => 12_000_000, 'currency' => 'ARS', 'frequency' => 'mensual']);
        $payload = quotePayload(['items' => [['service_id' => $service->id, 'description' => 'SEO', 'quantity' => 1, 'unit_price' => '120000', 'frequency' => 'mensual']]]);

        $this->actingAs($this->duena)->post('/presupuestos', $payload)->assertRedirect();
        $service->update(['price' => 99_900_000]);

        expect(Quote::first()->items[0]->unit_price)->toBe(12_000_000);
    });
});

describe('validación', function () {
    it('exige cliente o nombre de prospecto', function () {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload(['prospect_name' => null]))
            ->assertSessionHasErrors('prospect_name');
    });

    it('exige al menos un ítem', function () {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload(['items' => []]))->assertSessionHasErrors('items');
    });

    it('rechaza cantidades, precios y descuentos inválidos', function (array $override, string $errorKey) {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload($override))->assertSessionHasErrors($errorKey);
    })->with([
        'cantidad 0' => [['items' => [['description' => 'X', 'quantity' => 0, 'unit_price' => '10', 'frequency' => 'mensual']]], 'items.0.quantity'],
        'precio negativo' => [['items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => '-5', 'frequency' => 'mensual']]], 'items.0.unit_price'],
        'frecuencia inválida' => [['items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => '5', 'frequency' => 'anual']]], 'items.0.frequency'],
        'descuento > 100' => [['discount_percent' => 150], 'discount_percent'],
        'moneda inválida' => [['currency' => 'EUR'], 'currency'],
        'validez en el pasado' => [['valid_until' => '2020-01-01'], 'valid_until'],
        'CUIT inválido' => [['prospect_tax_id' => '30-71458963-9'], 'prospect_tax_id'],
        'email inválido' => [['prospect_email' => 'no-es-un-email'], 'prospect_email'],
    ]);

    it('rechaza un cliente inexistente', function () {
        $this->actingAs($this->duena)->post('/presupuestos', quotePayload(['client_id' => 9999]))->assertSessionHasErrors('client_id');
    });
});

describe('edición y estados', function () {
    it('edita un borrador reemplazando los ítems y recalculando', function () {
        $quote = makeQuote($this->duena);

        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload([
            'discount_percent' => 0,
            'items' => [['description' => 'Solo uno', 'quantity' => 3, 'unit_price' => '1000', 'frequency' => 'unico']],
        ]))->assertRedirect();

        $fresh = $quote->fresh()->load('items');
        expect($fresh->items)->toHaveCount(1)->and($fresh->total)->toBe(300_000)->and($fresh->number)->toBe($quote->number);
    });

    it('no permite editar uno aprobado ni rechazado', function (QuoteStatus $status) {
        $quote = makeQuote($this->duena);
        $quote->forceFill(['status' => $status])->save();

        $this->actingAs($this->duena)->get("/presupuestos/{$quote->id}/edit")->assertForbidden();
        $this->actingAs($this->duena)->put("/presupuestos/{$quote->id}", quotePayload())->assertForbidden();
    })->with([QuoteStatus::Aprobado, QuoteStatus::Rechazado]);

    it('solo se eliminan los borradores', function () {
        $draft = makeQuote($this->duena);
        $sent = makeQuote($this->duena);
        $sent->forceFill(['status' => QuoteStatus::Enviado])->save();

        $this->actingAs($this->duena)->delete("/presupuestos/{$sent->id}")->assertForbidden();
        $this->actingAs($this->duena)->delete("/presupuestos/{$draft->id}")->assertRedirect('/presupuestos');

        expect(Quote::find($draft->id))->toBeNull()->and(Quote::find($sent->id))->not->toBeNull();
    });

    it('marca como rechazado y no deja rechazar dos veces', function () {
        $quote = makeQuote($this->duena);

        $this->actingAs($this->duena)->post("/presupuestos/{$quote->id}/rechazar")->assertRedirect();
        expect($quote->fresh()->status)->toBe(QuoteStatus::Rechazado)->and($quote->fresh()->rejected_at)->not->toBeNull();

        $this->actingAs($this->duena)->post("/presupuestos/{$quote->id}/rechazar")->assertStatus(422);
    });

    it('un borrador con validez vencida figura como vencido', function () {
        $quote = makeQuote($this->duena, ['valid_until' => now()->subDay()->toDateString()]);

        expect($quote->effectiveStatus())->toBe(QuoteStatus::Vencido)->and($quote->isExpired())->toBeTrue();
    });
});

describe('listado', function () {
    it('filtra por estado, incluido vencido, y busca por número o destinatario', function () {
        $vigente = makeQuote($this->duena, ['prospect_name' => 'Vigente SA']);
        $vencido = makeQuote($this->duena, ['prospect_name' => 'Vencido SA', 'valid_until' => now()->subDays(3)->toDateString()]);
        $rechazado = makeQuote($this->duena, ['prospect_name' => 'Rechazado SA']);
        $rechazado->forceFill(['status' => QuoteStatus::Rechazado])->save();

        $this->actingAs($this->duena)->get('/presupuestos?status=vencido')
            ->assertSee('Vencido SA')->assertDontSee('Vigente SA')->assertDontSee('Rechazado SA');
        $this->actingAs($this->duena)->get('/presupuestos?status=borrador')
            ->assertSee('Vigente SA')->assertDontSee('Vencido SA');
        $this->actingAs($this->duena)->get('/presupuestos?status=rechazado')->assertSee('Rechazado SA')->assertDontSee('Vigente SA');
        $this->actingAs($this->duena)->get('/presupuestos?q='.$vigente->number)->assertSee('Vigente SA')->assertDontSee('Vencido SA');
        $this->actingAs($this->duena)->get('/presupuestos?q=vencido')->assertSee('Vencido SA')->assertDontSee('Vigente SA');
        expect($vencido->number)->not->toBe($vigente->number);
    });
});
