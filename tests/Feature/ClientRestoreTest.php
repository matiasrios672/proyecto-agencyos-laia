<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\VaultItem;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->admin = userWithRole('sysadmin');
    $this->camila = userWithRole('empleado');
});

/** Elimina un cliente por la vía normal (HTTP) para que quede el registro de actividad. */
function deleteClient($test, Client $client, $as = null): void
{
    $test->actingAs($as ?? $test->duena)->delete("/clientes/{$client->id}")->assertRedirect('/clientes');
}

describe('papelera', function () {
    it('Dirección y sysadmin ven los clientes eliminados con quién y cuándo los eliminó', function () {
        $client = Client::factory()->create(['business_name' => 'Cliente Borrado SA', 'trade_name' => null]);
        Client::factory()->create(['business_name' => 'Cliente Activo SA', 'trade_name' => null]);
        deleteClient($this, $client);

        foreach (['duena', 'admin'] as $who) {
            $this->actingAs($this->$who)->get('/clientes-eliminados')->assertOk()->assertInertia(fn ($p) => $p
                ->component('Clients/Trashed', false)
                ->has('clients.data', 1)
                ->where('clients.data.0.business_name', 'Cliente Borrado SA')
                ->where('clients.data.0.deleted_by', $this->duena->name));
        }
    });

    it('busca por nombre, nombre comercial o CUIT', function () {
        Client::factory()->create(['business_name' => 'Panadería Sol', 'trade_name' => 'La Espiga', 'tax_id' => '30-71458963-2'])->delete();
        Client::factory()->create(['business_name' => 'Taller Don Raúl', 'trade_name' => null])->delete();

        foreach (['sol' => 1, 'espiga' => 1, '30-71458963' => 1, 'raúl' => 1, 'nada' => 0, '' => 2] as $q => $count) {
            $this->actingAs($this->duena)->get('/clientes-eliminados?q='.urlencode($q))->assertInertia(fn ($p) => $p->has('clients.data', $count));
        }
    });

    it('muestra cuánta información conserva el cliente', function () {
        $service = makeService();
        makeInvoice(['service' => $service]);
        VaultItem::create(['client_id' => $service->client_id, 'category' => 'otro', 'label' => 'Hosting', 'secret' => 'x']);
        $service->client->delete();

        $this->actingAs($this->duena)->get('/clientes-eliminados')->assertInertia(fn ($p) => $p
            ->where('clients.data.0.services_count', 1)->where('clients.data.0.invoices_count', 1)->where('clients.data.0.vault_count', 1));
    });

    it('no incluye a los clientes activos', function () {
        Client::factory()->count(3)->create();

        $this->actingAs($this->duena)->get('/clientes-eliminados')->assertInertia(fn ($p) => $p->has('clients.data', 0));
    });

    it('el empleado recibe 403 y los invitados van al login', function () {
        $client = Client::factory()->create();
        $client->delete();
        $client->users()->attach($this->camila);

        $this->actingAs($this->camila)->get('/clientes-eliminados')->assertForbidden();
        $this->actingAs($this->camila)->post("/clientes-eliminados/{$client->id}/restaurar")->assertForbidden();
        auth()->logout();
        $this->get('/clientes-eliminados')->assertRedirect('/login');
        $this->post("/clientes-eliminados/{$client->id}/restaurar")->assertRedirect('/login');

        expect($client->fresh()->trashed())->toBeTrue();
    });

    it('un permiso extra clients.manage habilita la papelera a un empleado', function () {
        $this->camila->givePermissionTo('clients.manage');
        Client::factory()->create()->delete();

        $this->actingAs($this->camila->fresh())->get('/clientes-eliminados')->assertOk();
    });
});

describe('restaurar', function () {
    it('reactiva el cliente con servicios, bóveda y cobros, y deja registro', function () {
        $service = makeService();
        $client = $service->client;
        $invoice = makeInvoice(['service' => $service]);
        VaultItem::create(['client_id' => $client->id, 'category' => 'hosting', 'label' => 'cPanel', 'secret' => 'secreto']);
        $client->users()->attach($this->camila);
        deleteClient($this, $client);
        $this->actingAs($this->camila)->get("/clientes/{$client->id}")->assertNotFound();

        $this->actingAs($this->duena)->post("/clientes-eliminados/{$client->id}/restaurar")
            ->assertRedirect("/clientes/{$client->id}")->assertSessionHas('success');

        $fresh = $client->fresh();
        expect($fresh->trashed())->toBeFalse()
            ->and($fresh->services()->count())->toBe(1)
            ->and($fresh->vaultItems()->count())->toBe(1)
            ->and(Invoice::where('client_id', $client->id)->count())->toBe(1)
            ->and($fresh->users()->count())->toBe(1);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'clients', 'description' => 'Cliente restaurado', 'subject_id' => $client->id, 'causer_id' => $this->duena->id]);

        // Vuelve a estar visible para su equipo y en el listado.
        $this->actingAs($this->camila)->get("/clientes/{$client->id}")->assertOk();
        $this->actingAs($this->duena)->get('/clientes')->assertSee($client->business_name);
        $this->actingAs($this->duena)->get('/clientes-eliminados')->assertInertia(fn ($p) => $p->has('clients.data', 0));
        expect($invoice->fresh()->client_id)->toBe($client->id);
    });

    it('el sysadmin también puede restaurar', function () {
        $client = Client::factory()->create();
        $client->delete();

        $this->actingAs($this->admin)->post("/clientes-eliminados/{$client->id}/restaurar")->assertRedirect();

        expect($client->fresh()->trashed())->toBeFalse();
    });

    it('un cliente sin CUIT se restaura aunque haya otros sin CUIT', function () {
        $a = Client::factory()->create(['tax_id' => null]);
        Client::factory()->create(['tax_id' => null]);
        $a->delete();

        $this->actingAs($this->duena)->post("/clientes-eliminados/{$a->id}/restaurar")->assertSessionHasNoErrors();

        expect($a->fresh()->trashed())->toBeFalse();
    });

    it('rechaza la restauración si otro cliente activo ya usa su CUIT', function () {
        $viejo = Client::factory()->create(['business_name' => 'Panadería Vieja', 'trade_name' => null, 'tax_id' => '30-71458963-2']);
        deleteClient($this, $viejo);

        // Mientras estaba eliminado, el mismo CUIT se cargó en otro cliente (la base ya lo permite).
        $this->actingAs($this->duena)->post('/clientes', ['business_name' => 'Panadería Nueva', 'tax_id' => '30714589632'])->assertRedirect();

        $this->actingAs($this->duena)->post("/clientes-eliminados/{$viejo->id}/restaurar")
            ->assertSessionHasErrors('restore');

        expect($viejo->fresh()->trashed())->toBeTrue();
        expect(session('errors')->first('restore'))->toContain('30-71458963-2')->toContain('Panadería Nueva')->toContain('ya lo usa');
        $this->assertDatabaseMissing('activity_log', ['description' => 'Cliente restaurado']);
    });

    it('al cambiar el CUIT del cliente activo, la restauración vuelve a ser posible', function () {
        $viejo = Client::factory()->create(['tax_id' => '30-71458963-2']);
        deleteClient($this, $viejo);
        $nuevo = Client::factory()->create(['tax_id' => '30-71458963-2']);

        $this->actingAs($this->duena)->post("/clientes-eliminados/{$viejo->id}/restaurar")->assertSessionHasErrors('restore');

        $nuevo->update(['tax_id' => '20-12345678-6']);
        $this->actingAs($this->duena)->post("/clientes-eliminados/{$viejo->id}/restaurar")->assertSessionHasNoErrors();

        expect($viejo->fresh()->trashed())->toBeFalse();
    });

    it('da 404 para un cliente que no está eliminado o no existe', function () {
        $activo = Client::factory()->create();

        $this->actingAs($this->duena)->post("/clientes-eliminados/{$activo->id}/restaurar")->assertNotFound();
        $this->actingAs($this->duena)->post('/clientes-eliminados/999999/restaurar')->assertNotFound();
        $this->actingAs($this->duena)->post('/clientes-eliminados/no-es-un-id/restaurar')->assertNotFound();
    });

    it('restaurar dos veces seguidas no duplica nada', function () {
        $client = Client::factory()->create();
        $client->delete();

        $this->actingAs($this->duena)->post("/clientes-eliminados/{$client->id}/restaurar")->assertRedirect();
        $this->actingAs($this->duena)->post("/clientes-eliminados/{$client->id}/restaurar")->assertNotFound();

        expect(\DB::table('activity_log')->where('description', 'Cliente restaurado')->count())->toBe(1);
    });
});

describe('CUIT y clientes eliminados', function () {
    it('se puede crear un cliente con el CUIT de uno eliminado (antes fallaba con un error 500 en la base)', function () {
        $viejo = Client::factory()->create(['tax_id' => '30-71458963-2']);
        deleteClient($this, $viejo);

        $this->actingAs($this->duena)->post('/clientes', ['business_name' => 'Cliente Nuevo', 'tax_id' => '30714589632'])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(Client::where('tax_id', '30-71458963-2')->count())->toBe(1);
    });

    it('entre clientes activos el CUIT sigue siendo único, también a nivel base de datos', function () {
        Client::factory()->create(['tax_id' => '30-71458963-2']);

        $this->actingAs($this->duena)->post('/clientes', ['business_name' => 'Duplicado', 'tax_id' => '30714589632'])->assertSessionHasErrors('tax_id');
        expect(fn () => Client::factory()->create(['tax_id' => '30-71458963-2']))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
    });
});
