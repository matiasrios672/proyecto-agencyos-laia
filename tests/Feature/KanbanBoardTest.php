<?php

use App\Models\Client;
use App\Models\KanbanCard;
use App\Models\User;
use App\Notifications\CardAssigned;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->admin = userWithRole('sysadmin');
    $this->camila = userWithRole('empleado');
    $this->tomas = userWithRole('empleado');

    $this->client = Client::factory()->create(['trade_name' => null, 'business_name' => 'Panadería La Espiga']);
    $this->client->users()->attach([$this->camila->id, $this->tomas->id]);
    $this->board = $this->client->ensureBoard();
    $this->col = $this->board->columns->keyBy('key');   // pendientes / listo / publicado
});

/** Crea una tarjeta del tablero en la columna dada (posición al final). */
function card(array $attrs = []): KanbanCard
{
    $t = test();
    $columnId = $attrs['column_id'] ?? $t->col['pendientes']->id;

    return KanbanCard::create(array_replace([
        'board_id' => $t->board->id,
        'column_id' => $columnId,
        'title' => 'Reel de lanzamiento',
        'priority' => 'media',
        'position' => KanbanCard::where('column_id', $columnId)->count(),
        'created_by' => $t->duena->id,
    ], $attrs));
}

function boardUrl(Client $client, string $suffix = ''): string
{
    return "/clientes/{$client->id}/tablero{$suffix}";
}

function revokeContent(string $permission): void
{
    Role::findByName('empleado')->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

describe('acceso al tablero', function () {
    it('un empleado asignado ve el tablero con sus tres columnas', function () {
        card(['title' => 'Carrusel de recetas']);

        $this->actingAs($this->camila)->get(boardUrl($this->client))->assertOk()->assertInertia(fn ($p) => $p
            ->component('Kanban/Board', false)   // el verificador de Inertia busca "Pages/" (mayúscula); el proyecto usa "pages/"
            ->where('client.name', 'Panadería La Espiga')
            ->has('columns', 3)
            ->where('columns.0.name', 'Pendientes')
            ->where('columns.1.name', 'Listo')
            ->where('columns.2.name', 'Publicado')
            ->where('columns.0.cards.0.title', 'Carrusel de recetas')
            ->where('can.manage', true)
            ->has('team', 2));
    });

    it('crea el tablero la primera vez si el cliente todavía no tiene uno', function () {
        $nuevo = Client::factory()->create(['trade_name' => null]);
        $nuevo->users()->attach($this->camila);
        expect($nuevo->board)->toBeNull();

        $this->actingAs($this->camila)->get(boardUrl($nuevo))->assertOk()->assertInertia(fn ($p) => $p->has('columns', 3));

        expect($nuevo->fresh()->board)->not->toBeNull();
    });

    it('un empleado no asignado recibe 403 y no se crea ningún tablero', function () {
        $ajeno = Client::factory()->create(['trade_name' => null]);

        $this->actingAs($this->camila)->get(boardUrl($ajeno))->assertForbidden();

        expect($ajeno->fresh()->board)->toBeNull();
    });

    it('sin content.view no se entra aunque el cliente esté asignado', function () {
        revokeContent('content.view');

        $this->actingAs($this->camila->fresh())->get(boardUrl($this->client))->assertForbidden();
    });

    it('Dirección y sysadmin ven el tablero de cualquier cliente', function (string $who) {
        $this->actingAs($this->$who)->get(boardUrl($this->client))->assertOk();
    })->with(['duena', 'admin']);

    it('exige sesión', function () {
        $this->get(boardUrl($this->client))->assertRedirect('/login');
    });

    it('quien solo puede ver (sin content.manage) recibe can.manage = false', function () {
        revokeContent('content.manage');

        $this->actingAs($this->camila->fresh())->get(boardUrl($this->client))->assertOk()
            ->assertInertia(fn ($p) => $p->where('can.manage', false));
    });
});

describe('crear tarjetas', function () {
    $payload = fn (array $o = []) => array_replace(['title' => 'Reel de lanzamiento', 'priority' => 'alta'], $o);

    it('crea una tarjeta en la primera columna por defecto, al final', function () use ($payload) {
        card(['title' => 'Existente']);

        $this->actingAs($this->camila)->post(boardUrl($this->client, '/tarjetas'), $payload([
            'description' => 'Plano cenital', 'due_date' => today()->addDays(3)->toDateString(), 'assigned_user_id' => $this->tomas->id,
        ]))->assertRedirect();

        $card = KanbanCard::where('title', 'Reel de lanzamiento')->firstOrFail();
        expect($card->column_id)->toBe($this->col['pendientes']->id)
            ->and($card->position)->toBe(1)
            ->and($card->priority->value)->toBe('alta')
            ->and($card->assigned_user_id)->toBe($this->tomas->id)
            ->and($card->created_by)->toBe($this->camila->id)
            ->and($card->completed_at)->toBeNull();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'kanban', 'description' => 'Tarjeta creada']);
    });

    it('permite elegir columna; en Publicado nace completada', function () use ($payload) {
        $this->actingAs($this->camila)->post(boardUrl($this->client, '/tarjetas'), $payload(['column_id' => $this->col['publicado']->id]))->assertRedirect();

        expect(KanbanCard::firstOrFail()->completed_at)->not->toBeNull();
    });

    it('avisa al responsable, pero no a quien se asigna a sí mismo', function () use ($payload) {
        $this->actingAs($this->camila)->post(boardUrl($this->client, '/tarjetas'), $payload(['assigned_user_id' => $this->tomas->id]));
        $this->actingAs($this->camila)->post(boardUrl($this->client, '/tarjetas'), $payload(['title' => 'Para mí', 'assigned_user_id' => $this->camila->id]));

        $n = $this->tomas->notifications()->first();
        expect($n->type)->toBe(CardAssigned::class)
            ->and($n->data['url'])->toBe("/clientes/{$this->client->id}/tablero")
            ->and($n->data['body'])->toContain('Reel de lanzamiento')
            ->and($this->camila->notifications()->count())->toBe(0);
    });

    it('el responsable debe ser del equipo del cliente y estar activo', function () use ($payload) {
        $fueraDelEquipo = userWithRole('empleado');
        $baja = userWithRole('empleado', ['is_active' => false]);
        $this->client->users()->attach($baja);

        foreach ([$fueraDelEquipo, $baja] as $user) {
            $this->actingAs($this->camila)->post(boardUrl($this->client, '/tarjetas'), $payload(['assigned_user_id' => $user->id]))
                ->assertSessionHasErrors('assigned_user_id');
        }
        $this->actingAs($this->camila)->post(boardUrl($this->client, '/tarjetas'), $payload(['assigned_user_id' => 99999]))->assertSessionHasErrors('assigned_user_id');

        expect(KanbanCard::count())->toBe(0);
    });

    it('valida los campos', function (array $override, string $errorKey) use ($payload) {
        $this->actingAs($this->camila)->post(boardUrl($this->client, '/tarjetas'), $payload($override))->assertSessionHasErrors($errorKey);
    })->with([
        'sin título' => [['title' => ''], 'title'],
        'título larguísimo' => [['title' => str_repeat('a', 161)], 'title'],
        'prioridad inválida' => [['priority' => 'urgentisima'], 'priority'],
        'fecha inválida' => [['due_date' => 'mañana'], 'due_date'],
        'descripción enorme' => [['description' => str_repeat('x', 2001)], 'description'],
    ]);

    it('rechaza una columna de otro tablero', function () use ($payload) {
        $otro = Client::factory()->create(['trade_name' => null]);
        $columnaAjena = $otro->ensureBoard()->columns->first();

        $this->actingAs($this->camila)->post(boardUrl($this->client, '/tarjetas'), $payload(['column_id' => $columnaAjena->id]))->assertSessionHasErrors('column_id');

        expect(KanbanCard::count())->toBe(0);
    });

    it('sin content.manage o en un cliente ajeno recibe 403', function () use ($payload) {
        $ajeno = Client::factory()->create(['trade_name' => null]);
        $this->actingAs($this->camila)->post(boardUrl($ajeno, '/tarjetas'), $payload())->assertForbidden();

        revokeContent('content.manage');
        $this->actingAs($this->camila->fresh())->post(boardUrl($this->client, '/tarjetas'), $payload())->assertForbidden();

        expect(KanbanCard::count())->toBe(0);
    });
});

describe('editar y eliminar', function () {
    $payload = fn (array $o = []) => array_replace(['title' => 'Título nuevo', 'priority' => 'baja'], $o);

    it('edita los campos de la tarjeta', function () use ($payload) {
        $card = card();

        $this->actingAs($this->camila)->put(boardUrl($this->client, "/tarjetas/{$card->id}"), $payload([
            'description' => 'Nueva descripción', 'due_date' => '2026-12-24', 'assigned_user_id' => $this->tomas->id,
        ]))->assertRedirect();

        $fresh = $card->fresh();
        expect($fresh->title)->toBe('Título nuevo')->and($fresh->priority->value)->toBe('baja')
            ->and($fresh->due_date->toDateString())->toBe('2026-12-24')->and($fresh->assigned_user_id)->toBe($this->tomas->id)
            ->and($fresh->column_id)->toBe($this->col['pendientes']->id);
    });

    it('avisa solo cuando cambia el responsable', function () use ($payload) {
        $card = card();

        $this->actingAs($this->camila)->put(boardUrl($this->client, "/tarjetas/{$card->id}"), $payload(['assigned_user_id' => $this->tomas->id]));
        $this->actingAs($this->camila)->put(boardUrl($this->client, "/tarjetas/{$card->id}"), $payload(['title' => 'Otro título', 'assigned_user_id' => $this->tomas->id]));

        expect($this->tomas->notifications()->count())->toBe(1);
    });

    it('quien ya estaba asignado y fue dado de baja no bloquea editar el resto', function () use ($payload) {
        $baja = userWithRole('empleado', ['is_active' => false]);
        $this->client->users()->attach($baja);
        $card = card(['assigned_user_id' => $baja->id]);

        $this->actingAs($this->camila)->put(boardUrl($this->client, "/tarjetas/{$card->id}"), $payload(['assigned_user_id' => $baja->id]))->assertSessionHasNoErrors();

        expect($card->fresh()->title)->toBe('Título nuevo');
    });

    it('no se puede editar ni borrar la tarjeta de otro cliente usando la URL de uno propio', function () use ($payload) {
        $otroCliente = Client::factory()->create(['trade_name' => null]);
        $otroCliente->users()->attach($this->camila);
        $tarjetaAjena = KanbanCard::create([
            'board_id' => $otroCliente->ensureBoard()->id, 'column_id' => $otroCliente->board->columns->first()->id,
            'title' => 'De otro cliente', 'priority' => 'media', 'position' => 0,
        ]);

        $this->actingAs($this->camila)->put(boardUrl($this->client, "/tarjetas/{$tarjetaAjena->id}"), $payload())->assertNotFound();
        $this->actingAs($this->camila)->delete(boardUrl($this->client, "/tarjetas/{$tarjetaAjena->id}"))->assertNotFound();

        expect($tarjetaAjena->fresh()->title)->toBe('De otro cliente');
    });

    it('elimina la tarjeta y deja la columna sin huecos', function () {
        $a = card(['title' => 'A']);
        $b = card(['title' => 'B']);
        $c = card(['title' => 'C']);

        $this->actingAs($this->camila)->delete(boardUrl($this->client, "/tarjetas/{$a->id}"))->assertRedirect();

        expect(KanbanCard::find($a->id))->toBeNull()
            ->and($b->fresh()->position)->toBe(0)->and($c->fresh()->position)->toBe(1);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'kanban', 'description' => 'Tarjeta eliminada']);
    });

    it('sin permisos de gestión no edita ni elimina', function () use ($payload) {
        $card = card();
        revokeContent('content.manage');

        $this->actingAs($this->camila->fresh())->put(boardUrl($this->client, "/tarjetas/{$card->id}"), $payload())->assertForbidden();
        $this->actingAs($this->camila->fresh())->delete(boardUrl($this->client, "/tarjetas/{$card->id}"))->assertForbidden();

        expect($card->fresh()->title)->toBe('Reel de lanzamiento');
    });
});
