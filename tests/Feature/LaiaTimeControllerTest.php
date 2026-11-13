<?php

use App\Models\Client;
use App\Services\LaiaTime\LaiaTimeApi;
use App\Services\LaiaTime\LaiaTimeException;
use App\Support\Settings;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeLaiaTimeApi;

function ltBind(array $statuses = []): FakeLaiaTimeApi
{
    $fake = new FakeLaiaTimeApi($statuses);
    app()->instance(LaiaTimeApi::class, $fake);

    return $fake;
}

describe('acceso', function () {
    it('invitados van al login y empleados no ven la integración', function () {
        $this->get('/admin/laiatime')->assertRedirect('/login');
        $this->actingAs(userWithRole('empleado'))->get('/admin/laiatime')->assertForbidden();
        $this->actingAs(userWithRole('empleado'))->post('/admin/laiatime/sincronizar')->assertForbidden();
    });

    it('Dirección ve todo y sysadmin también', function () {
        ltBind();
        $this->actingAs(userWithRole('dueno'))->get('/admin/laiatime')->assertOk();
        $this->actingAs(userWithRole('sysadmin'))->get('/admin/laiatime')->assertOk();
    });

    it('quien solo puede ver finanzas no puede actuar', function () {
        $user = userWithRole('empleado');
        $user->givePermissionTo('finance.view');
        ltBind(['x' => 'active']);

        $this->actingAs($user)->get('/admin/laiatime')->assertOk();
        $this->actingAs($user)->post('/admin/laiatime/instancias/x/suspender')->assertForbidden();
        $this->actingAs($user)->post('/admin/laiatime/sincronizar')->assertForbidden();
    });
});

describe('listado', function () {
    it('muestra los negocios vinculados a su cliente y los IDs huérfanos', function () {
        ltBind(['a' => 'active', 'b' => 'past_due']);
        $client = Client::factory()->create(['laiatime_instance_id' => 'a']);
        Client::factory()->create(['laiatime_instance_id' => 'fantasma']);

        $this->actingAs(userWithRole('dueno'))->get('/admin/laiatime')->assertInertia(fn (Assert $p) => $p
            ->component('LaiaTime/Index', false)
            ->where('configured', true)
            ->has('instances', 2)
            ->where('instances.0.client.id', $client->id)
            ->where('instances.1.client', null)
            ->has('dangling', 1)
            ->where('dangling.0.instance_id', 'fantasma')
            ->where('can.manage', true));
    });

    it('sin configurar muestra el estado vacío, sin error', function () {
        ltBind()->configured = false;

        $this->actingAs(userWithRole('dueno'))->get('/admin/laiatime')->assertInertia(fn (Assert $p) => $p
            ->where('configured', false)->where('issue', 'not_configured')->where('error', null)->has('instances', 0));
    });

    it('si la API no responde muestra un mensaje amable en lugar de fallar', function () {
        ltBind()->failure = LaiaTimeException::network();

        $this->actingAs(userWithRole('dueno'))->get('/admin/laiatime')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('error.kind', 'unavailable')
            ->where('error.message', LaiaTimeException::UNAVAILABLE_MESSAGE)
            ->has('dangling', 0));
    });
});

describe('acciones', function () {
    it('probar conexión informa éxito o el motivo', function () {
        $fake = ltBind();
        $user = userWithRole('dueno');

        $this->actingAs($user)->post('/admin/laiatime/probar')->assertSessionHas('success');

        $fake->failure = LaiaTimeException::auth();
        $this->actingAs($user)->post('/admin/laiatime/probar')->assertSessionHas('error', LaiaTimeException::auth()->getMessage());
    });

    it('sincronizar corre el sincronizador y resume', function () {
        Settings::set('dunning.auto_suspend', '1');
        $fake = ltBind(['x' => 'active']);
        $client = Client::factory()->create(['laiatime_instance_id' => 'x']);
        makeInvoice(['service' => makeService(client: $client), 'due_date' => today()->subDays(20)->toDateString()]);

        $this->actingAs(userWithRole('dueno'))->post('/admin/laiatime/sincronizar')->assertSessionHas('success');

        expect($fake->writes)->toBe(['past_due:x']);
    });

    it('sincronizar sin configurar devuelve un error legible', function () {
        ltBind()->configured = false;

        $this->actingAs(userWithRole('dueno'))->post('/admin/laiatime/sincronizar')->assertSessionHas('error');
    });

    it('informar mora a mano marca al cliente como no automático', function () {
        $fake = ltBind(['x' => 'active']);
        $client = Client::factory()->create(['laiatime_instance_id' => 'x', 'laiatime_override_until' => today()->addDays(3)]);

        $this->actingAs(userWithRole('dueno'))->post('/admin/laiatime/instancias/x/suspender')->assertSessionHas('success');

        $client->refresh();
        expect($fake->writes)->toBe(['past_due:x'])
            ->and($client->laiatime_status)->toBe('past_due')
            ->and($client->laiatime_suspended_by_system)->toBeFalse()
            ->and($client->laiatime_override_until)->toBeNull();
    });

    it('reactivar a un cliente que sigue en mora le concede un nuevo plazo de gracia', function () {
        Settings::set('dunning.grace_days', '10');
        $fake = ltBind(['x' => 'past_due']);
        $client = Client::factory()->create(['laiatime_instance_id' => 'x', 'laiatime_status' => 'past_due', 'laiatime_suspended_by_system' => true]);
        makeInvoice(['service' => makeService(client: $client), 'due_date' => today()->subDays(30)->toDateString()]);

        $this->actingAs(userWithRole('dueno'))->post('/admin/laiatime/instancias/x/reactivar')->assertSessionHas('success');

        $client->refresh();
        expect($fake->writes)->toBe(['active:x'])
            ->and($client->laiatime_status)->toBe('active')
            ->and($client->laiatime_suspended_by_system)->toBeFalse()
            ->and($client->laiatime_override_until->toDateString())->toBe(today()->addDays(10)->toDateString());
    });

    it('una suscripción en TRIAL no se modifica y se explica', function () {
        $fake = ltBind(['x' => 'trial']);

        $this->actingAs(userWithRole('dueno'))->post('/admin/laiatime/instancias/x/suspender')->assertSessionHas('error');

        expect($fake->writes)->toBe([]);
    });

    it('los pedidos al sistema externo están limitados por throttle', function () {
        ltBind();
        $user = userWithRole('dueno');

        foreach (range(1, 10) as $i) {
            $this->actingAs($user)->post('/admin/laiatime/probar')->assertSessionHas('success');
        }
        $this->actingAs($user)->post('/admin/laiatime/probar')->assertStatus(429);
    });
});

describe('panel del cliente', function () {
    it('devuelve el estado guardado y respeta la visibilidad del cliente', function () {
        ltBind(['x' => 'past_due']);
        $client = Client::factory()->create(['laiatime_instance_id' => 'x', 'laiatime_status' => 'active']);

        $this->actingAs(userWithRole('dueno'))->getJson("/clientes/{$client->id}/laiatime")
            ->assertOk()->assertJsonPath('linked', true)->assertJsonPath('client.laiatime_status', 'active')->assertJsonPath('can.manage', true);

        // Un empleado sin permiso de finanzas ni asignación no entra.
        $this->actingAs(userWithRole('empleado'))->getJson("/clientes/{$client->id}/laiatime")->assertForbidden();
    });

    it('con live=1 consulta a LaiaTime y guarda el estado', function () {
        ltBind(['x' => 'past_due']);
        $client = Client::factory()->create(['laiatime_instance_id' => 'x', 'laiatime_status' => 'active']);

        $this->actingAs(userWithRole('dueno'))->getJson("/clientes/{$client->id}/laiatime?live=1")
            ->assertOk()->assertJsonPath('client.laiatime_status', 'past_due');
    });

    it('si LaiaTime falla en la consulta en vivo, informa y conserva lo guardado', function () {
        ltBind()->failure = LaiaTimeException::network();
        $client = Client::factory()->create(['laiatime_instance_id' => 'x', 'laiatime_status' => 'active']);

        $this->actingAs(userWithRole('dueno'))->getJson("/clientes/{$client->id}/laiatime?live=1")
            ->assertOk()->assertJsonPath('error.kind', 'unavailable')->assertJsonPath('client.laiatime_status', 'active');
    });

    it('un cliente sin instancia se informa como no vinculado', function () {
        ltBind();
        $client = Client::factory()->create(['laiatime_instance_id' => null]);

        $this->actingAs(userWithRole('dueno'))->getJson("/clientes/{$client->id}/laiatime")->assertOk()->assertJsonPath('linked', false);
    });
});
