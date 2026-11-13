<?php

use App\Models\Client;
use App\Models\VaultItem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->camila = userWithRole('empleado');
    $this->tomas = userWithRole('empleado');
    $this->duena = userWithRole('dueno');

    $this->client = Client::factory()->create();
    $this->client->users()->attach($this->camila);

    $this->item = $this->client->vaultItems()->create([
        'category' => 'red_social', 'label' => 'Instagram', 'username' => '@laespiga',
        'secret' => 'Clave-ultra-secreta', 'notes' => 'Nota privada',
    ]);
});

it('guarda secreto y notas cifrados en la base de datos', function () {
    $raw = DB::table('vault_items')->where('id', $this->item->id)->first();

    expect($raw->secret)->not->toContain('Clave-ultra-secreta')
        ->and(Crypt::decryptString($raw->secret))->toBe('Clave-ultra-secreta')
        ->and($raw->notes)->not->toContain('Nota privada');
});

it('nunca serializa el secreto en la ficha del cliente', function () {
    $response = $this->actingAs($this->camila)->get("/clientes/{$this->client->id}");

    $response->assertOk()->assertSee('Instagram');
    expect($response->getContent())->not->toContain('Clave-ultra-secreta')->not->toContain('Nota privada');
    expect($this->item->toArray())->not->toHaveKeys(['secret', 'notes']);
});

describe('revelado y copia', function () {
    it('entrega el secreto y deja registro de auditoría', function (string $action) {
        $this->actingAs($this->camila)
            ->postJson("/clientes/{$this->client->id}/boveda/{$this->item->id}/revelar", ['action' => $action])
            ->assertOk()
            ->assertJson(['value' => 'Clave-ultra-secreta'])
            ->assertHeader('Cache-Control');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'vault', 'event' => $action, 'causer_id' => $this->camila->id,
            'subject_id' => $this->item->id,
        ]);
    })->with(['read', 'copy']);

    it('no escribe el secreto en el log de actividad', function () {
        $this->actingAs($this->camila)
            ->postJson("/clientes/{$this->client->id}/boveda/{$this->item->id}/revelar", ['action' => 'read']);

        $logs = DB::table('activity_log')->where('log_name', 'vault')->get()->toJson();

        expect($logs)->not->toContain('Clave-ultra-secreta');
    });

    it('prohíbe a un empleado no asignado', function () {
        $this->actingAs($this->tomas)
            ->postJson("/clientes/{$this->client->id}/boveda/{$this->item->id}/revelar", ['action' => 'read'])
            ->assertForbidden();

        expect(DB::table('activity_log')->where('log_name', 'vault')->count())->toBe(0);
    });

    it('prohíbe sin el permiso vault.view aunque esté asignado', function () {
        // El permiso viene del rol: se quita del rol (desde la matriz del sysadmin).
        Role::findByName('empleado')->revokePermissionTo('vault.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->camila->fresh())
            ->postJson("/clientes/{$this->client->id}/boveda/{$this->item->id}/revelar", ['action' => 'read'])
            ->assertForbidden();
    });

    it('no permite revelar un ítem de otro cliente usando la URL de uno propio', function () {
        $otro = Client::factory()->create();
        $itemAjeno = $otro->vaultItems()->create(['category' => 'otro', 'label' => 'X', 'secret' => 'ajeno']);

        $this->actingAs($this->camila)
            ->postJson("/clientes/{$this->client->id}/boveda/{$itemAjeno->id}/revelar", ['action' => 'read'])
            ->assertNotFound();
    });

    it('valida la acción', function () {
        $this->actingAs($this->camila)
            ->postJson("/clientes/{$this->client->id}/boveda/{$this->item->id}/revelar", ['action' => 'hackear'])
            ->assertUnprocessable();
    });
});

describe('gestión de credenciales', function () {
    it('el empleado (sin vault.manage) no puede crear ni editar', function () {
        $this->actingAs($this->camila)->post("/clientes/{$this->client->id}/boveda", [
            'category' => 'hosting', 'label' => 'Hosting', 'secret' => 'x',
        ])->assertForbidden();

        $this->actingAs($this->camila)->put("/clientes/{$this->client->id}/boveda/{$this->item->id}", [
            'category' => 'hosting', 'label' => 'Hack',
        ])->assertForbidden();
    });

    it('dirección crea una credencial y queda auditado', function () {
        $this->actingAs($this->duena)->post("/clientes/{$this->client->id}/boveda", [
            'category' => 'hosting', 'label' => 'cPanel', 'username' => 'admin', 'secret' => 'cp-pass-123',
        ])->assertRedirect();

        $item = VaultItem::where('label', 'cPanel')->first();
        expect($item->secret)->toBe('cp-pass-123')->and($item->created_by)->toBe($this->duena->id);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'vault', 'event' => 'create']);
    });

    it('editar con secreto vacío conserva el actual', function () {
        $this->actingAs($this->duena)->put("/clientes/{$this->client->id}/boveda/{$this->item->id}", [
            'category' => 'red_social', 'label' => 'Instagram (nuevo)', 'secret' => '',
        ])->assertRedirect();

        $fresh = $this->item->fresh();
        expect($fresh->label)->toBe('Instagram (nuevo)')->and($fresh->secret)->toBe('Clave-ultra-secreta');
    });

    it('dirección elimina una credencial', function () {
        $this->actingAs($this->duena)->delete("/clientes/{$this->client->id}/boveda/{$this->item->id}")->assertRedirect();

        $this->assertDatabaseMissing('vault_items', ['id' => $this->item->id]);
    });
});
