<?php

use App\Models\CatalogService;
use App\Models\Client;
use App\Models\ClientService;
use App\Notifications\ClientAssigned;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->camila = userWithRole('empleado');
    $this->julian = userWithRole('empleado');
});

describe('catálogo', function () {
    it('dirección crea, edita y elimina servicios', function () {
        $this->actingAs($this->duena)->post('/catalogo', [
            'name' => 'Community Management', 'price' => '280000.50', 'currency' => 'ARS', 'frequency' => 'mensual',
        ])->assertRedirect();

        $service = CatalogService::firstOrFail();
        expect($service->price)->toBe(28_000_050)->and($service->is_active)->toBeTrue();

        $this->actingAs($this->duena)->put("/catalogo/{$service->id}", [
            'name' => 'CM Premium', 'price' => '350000', 'currency' => 'USD', 'frequency' => 'unico', 'is_active' => false,
        ])->assertRedirect();
        expect($service->fresh()->name)->toBe('CM Premium')->and($service->fresh()->is_active)->toBeFalse();

        $this->actingAs($this->duena)->delete("/catalogo/{$service->id}")->assertRedirect();
        expect(CatalogService::count())->toBe(0);
    });

    it('valida los campos', function () {
        $this->actingAs($this->duena)->post('/catalogo', ['name' => '', 'price' => -1, 'currency' => 'EUR', 'frequency' => 'x'])
            ->assertSessionHasErrors(['name', 'price', 'currency', 'frequency']);
    });

    it('el empleado no puede ver ni modificar', function () {
        $service = CatalogService::create(['name' => 'X', 'price' => 100, 'currency' => 'ARS', 'frequency' => 'mensual']);

        $this->actingAs($this->camila)->post('/catalogo', ['name' => 'Y', 'price' => 1, 'currency' => 'ARS', 'frequency' => 'mensual'])->assertForbidden();
        $this->actingAs($this->camila)->put("/catalogo/{$service->id}", ['name' => 'Z', 'price' => 1, 'currency' => 'ARS', 'frequency' => 'mensual'])->assertForbidden();
        $this->actingAs($this->camila)->delete("/catalogo/{$service->id}")->assertForbidden();
    });

    it('eliminar un servicio no rompe presupuestos ya emitidos', function () {
        $service = CatalogService::create(['name' => 'SEO', 'price' => 100_00, 'currency' => 'ARS', 'frequency' => 'mensual']);
        $quote = makeQuote($this->duena, ['items' => [['service_id' => $service->id, 'description' => 'SEO', 'quantity' => 1, 'unit_price' => '100', 'frequency' => 'mensual']]]);

        $this->actingAs($this->duena)->delete("/catalogo/{$service->id}")->assertRedirect();

        expect($quote->fresh()->items[0]->service_id)->toBeNull()->and($quote->fresh()->items[0]->description)->toBe('SEO');
    });
});

describe('servicios contratados', function () {
    beforeEach(function () {
        $this->client = Client::factory()->create();
        $this->client->users()->attach($this->camila);
        $this->service = ClientService::create([
            'client_id' => $this->client->id, 'name' => 'Community Management', 'amount' => 28_000_000, 'currency' => 'ARS',
            'frequency' => 'mensual', 'due_day' => 10, 'split_rule' => '100_agencia', 'status' => 'activo',
        ]);
        $this->payload = [
            'name' => 'Community Management', 'amount' => '280000', 'currency' => 'ARS', 'frequency' => 'mensual',
            'due_day' => 10, 'assigned_user_id' => $this->julian->id, 'split_rule' => 'porcentaje', 'split_percent' => 25, 'status' => 'activo',
        ];
    });

    it('el empleado ve nombre y estado pero no montos ni reparto', function () {
        $content = $this->actingAs($this->camila)->get("/clientes/{$this->client->id}")->assertOk()->getContent();

        expect($content)->toContain('Community Management')
            ->not->toContain('amount_label')->not->toContain('split_label')->not->toContain('280000');
    });

    it('dirección ve montos y reparto', function () {
        $this->actingAs($this->duena)->get("/clientes/{$this->client->id}")
            ->assertSee('amount_label')->assertSee('split_label');
    });

    it('dirección edita el servicio y asigna empleado y reparto', function () {
        $this->actingAs($this->duena)->put("/clientes/{$this->client->id}/servicios/{$this->service->id}", $this->payload)->assertRedirect();

        $fresh = $this->service->fresh();
        expect($fresh->assigned_user_id)->toBe($this->julian->id)
            ->and($fresh->split_rule->value)->toBe('porcentaje')
            ->and((float) $fresh->split_percent)->toBe(25.0)
            ->and($fresh->employeeShare(10_000_000))->toBe(2_500_000);
    });

    it('quien queda asignado ve el cliente y recibe aviso', function () {
        $this->actingAs($this->duena)->put("/clientes/{$this->client->id}/servicios/{$this->service->id}", $this->payload);

        expect($this->client->users()->pluck('users.id')->all())->toContain($this->julian->id)
            ->and($this->julian->notifications()->first()->type)->toBe(ClientAssigned::class);
    });

    it('sin empleado el reparto vuelve a 100 % agencia', function () {
        $this->actingAs($this->duena)->put("/clientes/{$this->client->id}/servicios/{$this->service->id}", [...$this->payload, 'assigned_user_id' => null]);

        $fresh = $this->service->fresh();
        expect($fresh->split_rule->value)->toBe('100_agencia')->and($fresh->split_percent)->toBeNull()->and($fresh->employeeShare(1_000_000))->toBe(0);
    });

    it('valida porcentaje, monto fijo, día y empleado', function (array $override, string $errorKey) {
        $this->actingAs($this->duena)->put("/clientes/{$this->client->id}/servicios/{$this->service->id}", [...$this->payload, ...$override])
            ->assertSessionHasErrors($errorKey);
    })->with([
        'porcentaje > 100' => [['split_percent' => 101], 'split_percent'],
        'porcentaje requerido' => [['split_percent' => null], 'split_percent'],
        'monto fijo requerido' => [['split_rule' => 'monto_fijo', 'split_amount' => null], 'split_amount'],
        'monto fijo mayor al servicio' => [['split_rule' => 'monto_fijo', 'split_amount' => '999999'], 'split_amount'],
        'día 31' => [['due_day' => 31], 'due_day'],
        'estado inválido' => [['status' => 'zombie'], 'status'],
        'empleado inexistente' => [['assigned_user_id' => 99999], 'assigned_user_id'],
    ]);

    it('no asigna a un sysadmin ni a un empleado dado de baja', function () {
        $baja = userWithRole('empleado', ['is_active' => false]);

        foreach ([$baja, userWithRole('sysadmin')] as $user) {
            $this->actingAs($this->duena)->put("/clientes/{$this->client->id}/servicios/{$this->service->id}", [...$this->payload, 'assigned_user_id' => $user->id])
                ->assertSessionHasErrors('assigned_user_id');
        }
    });

    it('el empleado no puede crear, editar ni borrar servicios', function () {
        $this->actingAs($this->camila)->post("/clientes/{$this->client->id}/servicios", $this->payload)->assertForbidden();
        $this->actingAs($this->camila)->put("/clientes/{$this->client->id}/servicios/{$this->service->id}", $this->payload)->assertForbidden();
        $this->actingAs($this->camila)->delete("/clientes/{$this->client->id}/servicios/{$this->service->id}")->assertForbidden();
    });

    it('no permite tocar un servicio de otro cliente usando la URL de uno propio', function () {
        $otro = Client::factory()->create();

        $this->actingAs($this->duena)->put("/clientes/{$otro->id}/servicios/{$this->service->id}", $this->payload)->assertNotFound();
    });

    it('agrega y elimina servicios contratados', function () {
        $this->actingAs($this->duena)->post("/clientes/{$this->client->id}/servicios", [...$this->payload, 'name' => 'Mantenimiento web'])->assertRedirect();
        expect($this->client->services()->count())->toBe(2);

        $new = $this->client->services()->where('name', 'Mantenimiento web')->first();
        $this->actingAs($this->duena)->delete("/clientes/{$this->client->id}/servicios/{$new->id}")->assertRedirect();
        expect($this->client->services()->count())->toBe(1);
    });
});
