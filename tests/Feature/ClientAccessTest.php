<?php

use App\Enums\AccountStatus;
use App\Models\Client;

beforeEach(function () {
    $this->camila = userWithRole('empleado');
    $this->tomas = userWithRole('empleado');
    $this->duena = userWithRole('dueno');
    $this->admin = userWithRole('sysadmin');

    $this->clientDeCamila = Client::factory()->create(['business_name' => 'Cliente de Camila']);
    $this->clientDeTomas = Client::factory()->create(['business_name' => 'Cliente de Tomas']);
    $this->clientDeCamila->users()->attach($this->camila);
    $this->clientDeTomas->users()->attach($this->tomas);
});

describe('visibilidad por asignación', function () {
    it('el empleado solo lista sus clientes asignados', function () {
        $this->actingAs($this->camila)->get('/clientes')
            ->assertOk()
            ->assertSee('Cliente de Camila')
            ->assertDontSee('Cliente de Tomas');
    });

    it('el empleado recibe 403 al abrir un cliente ajeno', function () {
        $this->actingAs($this->camila)->get("/clientes/{$this->clientDeTomas->id}")->assertForbidden();
    });

    it('el empleado puede abrir su cliente', function () {
        $this->actingAs($this->camila)->get("/clientes/{$this->clientDeCamila->id}")->assertOk();
    });

    it('dirección y sysadmin ven todos los clientes', function (string $who) {
        $this->actingAs($this->$who)->get('/clientes')
            ->assertSee('Cliente de Camila')
            ->assertSee('Cliente de Tomas');
    })->with(['duena', 'admin']);

    it('un permiso extra clients.view_all da acceso a todos', function () {
        $this->camila->givePermissionTo('clients.view_all');

        $this->actingAs($this->camila->fresh())->get("/clientes/{$this->clientDeTomas->id}")->assertOk();
    });
});

describe('gestión de clientes', function () {
    it('el empleado no puede crear, editar ni borrar', function () {
        $this->actingAs($this->camila)->get('/clientes/create')->assertForbidden();
        $this->actingAs($this->camila)->post('/clientes', ['business_name' => 'X'])->assertForbidden();
        $this->actingAs($this->camila)->put("/clientes/{$this->clientDeCamila->id}", ['business_name' => 'X'])->assertForbidden();
        $this->actingAs($this->camila)->delete("/clientes/{$this->clientDeCamila->id}")->assertForbidden();
    });

    it('dirección crea un cliente con CUIT válido y lo normaliza', function () {
        $this->actingAs($this->duena)->post('/clientes', [
            'business_name' => 'Panadería La Espiga S.R.L.',
            'tax_id' => '30714589632',
            'tax_condition' => 'responsable_inscripto',
            'email' => 'hola@laespiga.com.ar',
        ])->assertRedirect();

        $client = Client::where('business_name', 'Panadería La Espiga S.R.L.')->first();
        expect($client)->not->toBeNull()
            ->and($client->tax_id)->toBe('30-71458963-2')
            ->and($client->account_status)->toBe(AccountStatus::AlDia);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'clients', 'description' => 'Cliente creado']);
    });

    it('rechaza un CUIT con dígito verificador incorrecto', function () {
        $this->actingAs($this->duena)->post('/clientes', ['business_name' => 'X', 'tax_id' => '30-71458963-9'])
            ->assertSessionHasErrors('tax_id');
    });

    it('rechaza CUIT duplicado', function () {
        $this->clientDeCamila->update(['tax_id' => '30-71458963-2']);

        $this->actingAs($this->duena)->post('/clientes', ['business_name' => 'Otro', 'tax_id' => '30714589632'])
            ->assertSessionHasErrors('tax_id');
    });

    it('ignora un estado de cuenta enviado a mano: el semáforo lo deriva el sistema de los cobros', function () {
        $this->actingAs($this->duena)->put("/clientes/{$this->clientDeCamila->id}", [
            'business_name' => 'Cliente de Camila', 'account_status' => 'deuda',
        ])->assertRedirect();

        expect($this->clientDeCamila->fresh()->account_status)->toBe(AccountStatus::AlDia);
    });

    it('filtra por estado y busca por nombre', function () {
        $this->clientDeTomas->update(['account_status' => AccountStatus::Deuda]);

        $this->actingAs($this->duena)->get('/clientes?status=deuda')
            ->assertSee('Cliente de Tomas')->assertDontSee('Cliente de Camila');

        $this->actingAs($this->duena)->get('/clientes?q=camila')
            ->assertSee('Cliente de Camila')->assertDontSee('Cliente de Tomas');
    });
});

describe('asignación de empleados', function () {
    it('dirección asigna empleados activos a un cliente', function () {
        $this->actingAs($this->duena)->put("/clientes/{$this->clientDeCamila->id}/equipo", [
            'user_ids' => [$this->camila->id, $this->tomas->id],
        ])->assertRedirect();

        expect($this->clientDeCamila->users()->pluck('users.id')->all())
            ->toContain($this->camila->id, $this->tomas->id);
        // Tomás ahora ve el cliente.
        $this->actingAs($this->tomas)->get("/clientes/{$this->clientDeCamila->id}")->assertOk();
    });

    it('ignora usuarios inactivos o sysadmin', function () {
        $baja = userWithRole('empleado', ['is_active' => false]);

        $this->actingAs($this->duena)->put("/clientes/{$this->clientDeCamila->id}/equipo", [
            'user_ids' => [$baja->id, $this->admin->id],
        ])->assertRedirect();

        expect($this->clientDeCamila->users()->count())->toBe(0);
    });

    it('el empleado no puede asignar', function () {
        $this->actingAs($this->camila)->put("/clientes/{$this->clientDeCamila->id}/equipo", ['user_ids' => []])
            ->assertForbidden();
    });
});
