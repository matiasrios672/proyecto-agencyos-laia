<?php

use App\Actions\Kanban\MoveCard;
use App\Models\Client;
use App\Models\KanbanCard;
use App\Models\User;
use App\Notifications\CardAssigned;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->duena = userWithRole('dueno');
    $this->camila = userWithRole('empleado');
    $this->tomas = userWithRole('empleado');

    $this->client = Client::factory()->create(['trade_name' => null]);
    $this->client->users()->attach([$this->camila->id, $this->tomas->id]);
    $this->board = $this->client->ensureBoard();
    $this->col = $this->board->columns->keyBy('key');
});

afterEach(fn () => Carbon::setTestNow());

function mk(string $title, string $column, ?array $extra = null): KanbanCard
{
    $t = test();
    $columnId = $t->col[$column]->id;

    return KanbanCard::create(array_replace([
        'board_id' => $t->board->id, 'column_id' => $columnId, 'title' => $title, 'priority' => 'media',
        'position' => KanbanCard::where('column_id', $columnId)->count(),
    ], $extra ?? []));
}

function moveUrl(Client $client, KanbanCard $card): string
{
    return "/clientes/{$client->id}/tablero/tarjetas/{$card->id}/mover";
}

/** @return array<string, list<string>> título de las tarjetas por columna, en orden de posición */
function layout(): array
{
    $t = test();

    return $t->board->columns()->get()->mapWithKeys(fn ($c) => [
        $c->key => KanbanCard::where('column_id', $c->id)->orderBy('position')->pluck('title')->all(),
    ])->all();
}

/** Las posiciones de cada columna son exactamente 0..n-1, sin huecos ni repetidas. */
function assertContiguous(): void
{
    foreach (test()->board->columns as $column) {
        $positions = KanbanCard::where('column_id', $column->id)->orderBy('position')->pluck('position')->all();
        expect($positions)->toBe(array_keys($positions), "posiciones corruptas en {$column->key}: ".json_encode($positions));
    }
}

describe('mover entre columnas', function () {
    it('mueve la tarjeta, reindexa origen y destino, y devuelve el estado final', function () {
        $a = mk('A', 'pendientes');
        $b = mk('B', 'pendientes');
        $c = mk('C', 'pendientes');
        $x = mk('X', 'listo');
        $y = mk('Y', 'listo');

        $response = $this->actingAs($this->camila)->postJson(moveUrl($this->client, $a), ['column_id' => $this->col['listo']->id, 'position' => 1])->assertOk();

        expect(layout())->toBe(['pendientes' => ['B', 'C'], 'listo' => ['X', 'A', 'Y'], 'publicado' => []]);
        assertContiguous();
        $response->assertJsonCount(5, 'cards')->assertJsonPath('cards.0.id', $b->id);
        expect($c->fresh()->position)->toBe(1)->and($y->fresh()->position)->toBe(2)->and($x->fresh()->position)->toBe(0);
    });

    it('reordena dentro de la misma columna hacia arriba y hacia abajo', function () {
        $cards = collect(['A', 'B', 'C', 'D'])->map(fn ($t) => mk($t, 'pendientes'));

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $cards[3]), ['column_id' => $this->col['pendientes']->id, 'position' => 0])->assertOk();
        expect(layout()['pendientes'])->toBe(['D', 'A', 'B', 'C']);

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $cards[3]), ['column_id' => $this->col['pendientes']->id, 'position' => 3])->assertOk();
        expect(layout()['pendientes'])->toBe(['A', 'B', 'C', 'D']);
        assertContiguous();
    });

    it('acota la posición pedida al rango de la columna', function () {
        $a = mk('A', 'pendientes');
        mk('X', 'listo');

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $a), ['column_id' => $this->col['listo']->id, 'position' => 9999])->assertOk();

        expect(layout()['listo'])->toBe(['X', 'A']);
        assertContiguous();
    });

    it('mover a una columna vacía funciona', function () {
        $a = mk('A', 'pendientes');

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $a), ['column_id' => $this->col['publicado']->id, 'position' => 0])->assertOk();

        expect(layout())->toBe(['pendientes' => [], 'listo' => [], 'publicado' => ['A']]);
    });

    it('mover a Publicado la completa y sacarla de ahí limpia la fecha', function () {
        $card = mk('A', 'pendientes');

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $card), ['column_id' => $this->col['publicado']->id, 'position' => 0]);
        $completedAt = $card->fresh()->completed_at;
        expect($completedAt)->not->toBeNull();

        // Reordenar dentro de Publicado no cambia la fecha original de completado.
        $other = mk('B', 'publicado', ['completed_at' => now()]);
        $this->travel(2)->hours();
        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $card), ['column_id' => $this->col['publicado']->id, 'position' => 1]);
        expect($card->fresh()->completed_at->equalTo($completedAt))->toBeTrue();

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $card), ['column_id' => $this->col['listo']->id, 'position' => 0]);
        expect($card->fresh()->completed_at)->toBeNull()->and($other->fresh()->completed_at)->not->toBeNull();
    });

    it('es idempotente: repetir el mismo movimiento deja el mismo estado y no vuelve a auditar', function () {
        $a = mk('A', 'pendientes');
        mk('B', 'pendientes');
        $body = ['column_id' => $this->col['listo']->id, 'position' => 0];

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $a), $body)->assertOk();
        $after = layout();
        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $a), $body)->assertOk();

        expect(layout())->toBe($after);
        assertContiguous();
        expect(DB::table('activity_log')->where('log_name', 'kanban')->where('description', 'like', 'Tarjeta movida%')->count())->toBe(1);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'kanban', 'description' => 'Tarjeta movida de «Pendientes» a «Listo»']);
    });

    it('reordenar dentro de la misma columna no genera auditoría (sin spam)', function () {
        mk('A', 'pendientes');
        $b = mk('B', 'pendientes');

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $b), ['column_id' => $this->col['pendientes']->id, 'position' => 0])->assertOk();

        expect(DB::table('activity_log')->where('log_name', 'kanban')->count())->toBe(0);
    });

    it('conserva las posiciones consecutivas tras una ráfaga de movimientos arbitrarios', function () {
        $cards = collect(range(1, 9))->map(fn ($i) => mk("T{$i}", ['pendientes', 'listo', 'publicado'][$i % 3]));
        $columns = $this->board->columns->pluck('id')->all();
        mt_srand(20261);

        foreach (range(1, 60) as $step) {
            $card = $cards[mt_rand(0, 8)];
            $this->actingAs($this->camila)->postJson(moveUrl($this->client, $card), [
                'column_id' => $columns[mt_rand(0, 2)], 'position' => mt_rand(0, 12),
            ])->assertOk();
            assertContiguous();
        }

        expect(KanbanCard::count())->toBe(9);
    });

    it('MoveCard serializa movimientos sucesivos de distintas tarjetas sin duplicar posiciones', function () {
        $cards = collect(['A', 'B', 'C', 'D', 'E'])->map(fn ($t) => mk($t, 'pendientes'));
        $move = app(MoveCard::class);

        // Dos "usuarios" mueven tarjetas distintas a la misma posición de la misma columna destino.
        $move->handle($cards[0]->fresh(), $this->col['listo']->id, 0, $this->camila);
        $move->handle($cards[4]->fresh(), $this->col['listo']->id, 0, $this->tomas);

        expect(layout()['listo'])->toBe(['E', 'A']);
        assertContiguous();
    });
});

describe('seguridad del movimiento', function () {
    it('una columna de otro tablero se rechaza y la tarjeta no cambia', function () {
        $card = mk('A', 'pendientes');
        $otro = Client::factory()->create(['trade_name' => null]);
        $columnaAjena = $otro->ensureBoard()->columns->first();

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $card), ['column_id' => $columnaAjena->id, 'position' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('column_id');

        expect($card->fresh()->column_id)->toBe($this->col['pendientes']->id);
        expect(fn () => app(MoveCard::class)->handle($card, $columnaAjena->id, 0, $this->camila))->toThrow(ValidationException::class);
    });

    it('no se puede mover la tarjeta de otro cliente usando la URL de uno propio', function () {
        $otro = Client::factory()->create(['trade_name' => null]);
        $otro->users()->attach($this->camila);
        $ajena = KanbanCard::create([
            'board_id' => $otro->ensureBoard()->id, 'column_id' => $otro->board->columns->first()->id,
            'title' => 'Ajena', 'priority' => 'media', 'position' => 0,
        ]);

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $ajena), ['column_id' => $this->col['listo']->id, 'position' => 0])->assertNotFound();

        expect($ajena->fresh()->column_id)->toBe($otro->board->columns->first()->id);
    });

    it('un empleado no asignado al cliente recibe 403', function () {
        $card = mk('A', 'pendientes');
        $intruso = userWithRole('empleado');

        $this->actingAs($intruso)->postJson(moveUrl($this->client, $card), ['column_id' => $this->col['listo']->id, 'position' => 0])->assertForbidden();

        expect($card->fresh()->column_id)->toBe($this->col['pendientes']->id);
    });

    it('sin content.manage no se puede mover', function () {
        $card = mk('A', 'pendientes');
        Role::findByName('empleado')->revokePermissionTo('content.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->camila->fresh())->postJson(moveUrl($this->client, $card), ['column_id' => $this->col['listo']->id, 'position' => 0])->assertForbidden();
    });

    it('valida el cuerpo', function (array $body, string $errorKey) {
        $card = mk('A', 'pendientes');

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $card), $body)->assertUnprocessable()->assertJsonValidationErrors($errorKey);
    })->with([
        'sin columna' => [['position' => 0], 'column_id'],
        'sin posición' => [['column_id' => 1], 'position'],
        'posición negativa' => [['column_id' => 1, 'position' => -1], 'position'],
        'posición no numérica' => [['column_id' => 1, 'position' => 'arriba'], 'position'],
        'columna inexistente' => [['column_id' => 'abc', 'position' => 0], 'column_id'],
    ]);

    it('una columna inexistente (id válido pero ajeno al tablero) se rechaza', function () {
        $card = mk('A', 'pendientes');

        $this->actingAs($this->camila)->postJson(moveUrl($this->client, $card), ['column_id' => 987654, 'position' => 0])->assertUnprocessable();
    });

    it('exige sesión', function () {
        $card = mk('A', 'pendientes');

        $this->postJson(moveUrl($this->client, $card), ['column_id' => $this->col['listo']->id, 'position' => 0])->assertUnauthorized();
    });
});

describe('Mis tareas', function () {
    function task(string $title, ?string $due, array $extra = []): KanbanCard
    {
        $t = test();

        return KanbanCard::create(array_replace([
            'board_id' => $t->board->id, 'column_id' => $t->col['pendientes']->id, 'title' => $title, 'priority' => 'media',
            'position' => KanbanCard::where('column_id', $t->col['pendientes']->id)->count(),
            'assigned_user_id' => $t->camila->id, 'due_date' => $due,
        ], $extra));
    }

    it('agrupa por vencimiento: vencidas, hoy, esta semana, más adelante y sin fecha', function () {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00')); // miércoles; la semana termina el domingo 22
        task('Ayer', '2026-11-17');
        task('Hoy', '2026-11-18');
        task('Viernes', '2026-11-20');
        task('Domingo', '2026-11-22');
        task('Lunes próximo', '2026-11-23');
        task('Sin fecha', null);

        $this->actingAs($this->camila)->get('/mis-tareas')->assertOk()->assertInertia(function ($p) {
            $p->component('Kanban/MyTasks', false)->where('total', 6);
            $groups = collect($p->toArray()['props']['groups'])->mapWithKeys(fn ($g) => [$g['key'] => collect($g['cards'])->pluck('title')->all()]);
            expect($groups->all())->toBe([
                'vencidas' => ['Ayer'], 'hoy' => ['Hoy'], 'semana' => ['Viernes', 'Domingo'],
                'mas_adelante' => ['Lunes próximo'], 'sin_fecha' => ['Sin fecha'],
            ]);
        });
    });

    it('solo trae mis tarjetas pendientes (no las de otros ni las completadas)', function () {
        task('Mía', null);
        task('De Tomás', null, ['assigned_user_id' => $this->tomas->id]);
        task('Publicada', null, ['column_id' => $this->col['publicado']->id, 'completed_at' => now()]);
        task('Sin responsable', null, ['assigned_user_id' => null]);

        $this->actingAs($this->camila)->get('/mis-tareas')->assertInertia(fn ($p) => $p->where('total', 1)
            ->where('groups.4.cards.0.title', 'Mía')
            ->where('groups.4.cards.0.client.id', $this->client->id));
    });

    it('no muestra tarjetas de clientes que ya no puedo ver ni de clientes inactivos', function () {
        task('Visible', null);
        $this->client->users()->detach($this->camila);

        $this->actingAs($this->camila)->get('/mis-tareas')->assertInertia(fn ($p) => $p->where('total', 0));

        $this->client->users()->attach($this->camila);
        $this->client->update(['is_active' => false]);
        $this->actingAs($this->camila)->get('/mis-tareas')->assertInertia(fn ($p) => $p->where('total', 0));
    });

    it('necesita content.view y sesión', function () {
        $this->get('/mis-tareas')->assertRedirect('/login');

        Role::findByName('empleado')->revokePermissionTo('content.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->camila->fresh())->get('/mis-tareas')->assertForbidden();
    });

    it('Dirección ve sus propias tarjetas asignadas en cualquier cliente', function () {
        task('De Dirección', null, ['assigned_user_id' => $this->duena->id]);

        $this->actingAs($this->duena)->get('/mis-tareas')->assertInertia(fn ($p) => $p->where('total', 1));
    });
});

it('el seeder siembra tableros con responsables del equipo de cada cliente y sin avisos de asignación', function () {
    $camila = User::factory()->create(['email' => 'camila@laia.test', 'is_active' => true]);
    $camila->assignRole('empleado');
    $cliente = Client::factory()->create(['business_name' => 'Panadería La Espiga S.R.L.', 'trade_name' => 'La Espiga']);
    $cliente->users()->attach($camila);

    (new Database\Seeders\KanbanSeeder)->run();

    $cards = KanbanCard::whereIn('board_id', $cliente->fresh()->board()->pluck('id'))->get();
    expect($cards)->not->toBeEmpty()
        ->and($cards->pluck('assigned_user_id')->unique()->all())->toBe([$camila->id])
        ->and($cards->where('column_id', $cliente->board->columns->firstWhere('key', 'publicado')->id)->every(fn ($c) => $c->completed_at !== null))->toBeTrue();
    expect($camila->notifications()->where('type', CardAssigned::class)->count())->toBe(0);
});
