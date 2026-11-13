<?php

use App\Models\Client;
use App\Models\User;
use App\Notifications\LaiaTimeAlert;
use App\Services\LaiaTime\LaiaTimeApi;
use App\Services\LaiaTime\LaiaTimeException;
use App\Services\LaiaTime\LaiaTimeInstance;
use App\Support\Settings;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeLaiaTimeApi;

/** Cliente vinculado a LaiaTime con un cobro vencido hace `$overdueDays` días (0 = sin deuda). */
function ltClient(string $instance, int $overdueDays = 0, array $attrs = []): Client
{
    $client = Client::factory()->create(array_replace(['laiatime_instance_id' => $instance], $attrs));

    if ($overdueDays > 0) {
        makeInvoice(['service' => makeService(client: $client), 'due_date' => today()->subDays($overdueDays)->toDateString()]);
    }

    return $client;
}

function ltFake(array $statuses): FakeLaiaTimeApi
{
    $fake = new FakeLaiaTimeApi($statuses);
    app()->instance(LaiaTimeApi::class, $fake);

    return $fake;
}

beforeEach(function () {
    Settings::set('dunning.auto_suspend', '1');
    Settings::set('dunning.grace_days', '10');
});

describe('laiatime:sync', function () {
    it('informa mora solo al pasar la gracia (más de 10 días)', function () {
        $fake = ltFake(['out' => 'active', 'edge' => 'active', 'ok' => 'active']);
        $out = ltClient('out', 11);
        $edge = ltClient('edge', 10);
        $ok = ltClient('ok');

        $this->artisan('laiatime:sync')->assertSuccessful();

        expect($fake->writes)->toBe(['past_due:out'])
            ->and($out->fresh()->laiatime_status)->toBe('past_due')
            ->and($out->fresh()->laiatime_suspended_by_system)->toBeTrue()
            ->and($out->fresh()->laiatime_suspended_at)->not->toBeNull()
            ->and($edge->fresh()->laiatime_status)->toBe('active')
            ->and($ok->fresh()->laiatime_synced_at)->not->toBeNull();
    });

    it('con la marca automática apagada no escribe nada', function () {
        Settings::set('dunning.auto_suspend', '0');
        $fake = ltFake(['out' => 'active']);
        ltClient('out', 30);

        $this->artisan('laiatime:sync')->assertSuccessful();

        expect($fake->writes)->toBe([]);
    });

    it('respeta la prórroga manual vigente y vuelve a marcar al vencer', function () {
        $fake = ltFake(['out' => 'active']);
        $client = ltClient('out', 30, ['laiatime_override_until' => today()->addDay()->toDateString()]);

        $this->artisan('laiatime:sync');
        expect($fake->writes)->toBe([]);

        $client->forceFill(["laiatime_override_until" => today()->subDay()->toDateString()])->save();
        $this->artisan('laiatime:sync');
        expect($fake->writes)->toBe(['past_due:out']);
    });

    it('no toca TRIAL ni CANCELED aunque estén en mora', function () {
        $fake = ltFake(['t' => 'trial', 'c' => 'canceled']);
        ltClient('t', 30);
        ltClient('c', 30);

        $this->artisan('laiatime:sync')->assertSuccessful();

        expect($fake->writes)->toBe([]);
    });

    it('reactiva solo lo que había informado el sistema, cuando el cliente regularizó', function () {
        $fake = ltFake(['sys' => 'past_due', 'manual' => 'past_due']);
        $sys = ltClient('sys', 0, ['laiatime_status' => 'past_due', 'laiatime_suspended_by_system' => true, 'laiatime_suspended_at' => now()->subDay()]);
        $manual = ltClient('manual', 0, ['laiatime_status' => 'past_due', 'laiatime_suspended_by_system' => false]);

        $this->artisan('laiatime:sync');

        expect($fake->writes)->toBe(['active:sys'])
            ->and($sys->fresh()->laiatime_status)->toBe('active')
            ->and($sys->fresh()->laiatime_suspended_by_system)->toBeFalse()
            ->and($sys->fresh()->laiatime_suspended_at)->toBeNull()
            ->and($manual->fresh()->laiatime_status)->toBe('past_due');
    });

    it('si el cliente sigue en mora fuera de gracia no se reactiva', function () {
        $fake = ltFake(['x' => 'past_due']);
        ltClient('x', 15, ['laiatime_suspended_by_system' => true]);

        $this->artisan('laiatime:sync');

        expect($fake->writes)->toBe([]);
    });

    it('una instancia que LaiaTime no conoce queda marcada como inexistente', function () {
        ltFake([]);
        $client = ltClient('fantasma', 30);

        $this->artisan('laiatime:sync')->assertSuccessful();

        expect($client->fresh()->laiatime_status)->toBe('missing')->and($client->fresh()->laiatime_last_error)->toContain('no existe');
    });

    it('--dry-run no escribe ni en LaiaTime ni en la base', function () {
        $fake = ltFake(['out' => 'active']);
        $client = ltClient('out', 30);

        $this->artisan('laiatime:sync --dry-run')->assertSuccessful();

        expect($fake->writes)->toBe([])->and($client->fresh()->laiatime_status)->toBeNull();
    });

    it('ignora clientes dados de baja', function () {
        $fake = ltFake(['out' => 'active']);
        ltClient('out', 30, ['is_active' => false]);

        $this->artisan('laiatime:sync');

        expect($fake->writes)->toBe([]);
    });

    it('sin configurar termina bien con un aviso; con la API caída, falla', function () {
        $fake = ltFake([]);
        $fake->configured = false;
        $this->artisan('laiatime:sync')->assertSuccessful();

        $fake->configured = true;
        $fake->failure = LaiaTimeException::network();
        ltClient('x');
        $this->artisan('laiatime:sync')->assertFailed();
    });

    it('un error en un cliente se guarda sin la API key y no frena a los demás', function () {
        Settings::set('laiatime.api_key', 'lt_live_abc123.s3cr3tvalue');
        $fake = ltFake(['bad' => 'active', 'good' => 'active']);
        $bad = ltClient('bad', 30);
        $good = ltClient('good', 30);

        $api = new class($fake) extends FakeLaiaTimeApi
        {
            public function __construct(private FakeLaiaTimeApi $inner) {}

            public function isConfigured(): bool { return true; }
            public function instances(): array { return $this->inner->instances(); }

            public function markPastDue(string $id): void
            {
                $id === 'bad' ? throw LaiaTimeException::rejected('falló con s3cr3tvalue') : $this->inner->markPastDue($id);
            }
        };
        app()->instance(LaiaTimeApi::class, $api);

        $this->artisan('laiatime:sync')->assertFailed();

        expect($bad->fresh()->laiatime_last_error)->toContain('[clave]')->not->toContain('s3cr3tvalue')
            ->and($fake->writes)->toBe(['past_due:good'])
            ->and($good->fresh()->laiatime_status)->toBe('past_due');
    });

    it('avisa a Dirección y sysadmin (no a empleados) y no repite la misma falla', function () {
        Notification::fake();
        $dueno = userWithRole('dueno');
        $admin = userWithRole('sysadmin');
        $empleado = userWithRole('empleado');
        ltFake(['out' => 'active']);
        ltClient('out', 30);
        ltClient('fantasma', 30);

        $this->artisan('laiatime:sync');

        Notification::assertSentTo([$dueno, $admin], LaiaTimeAlert::class, 2); // mora + inexistente
        Notification::assertNotSentTo($empleado, LaiaTimeAlert::class);

        Notification::fake();
        $this->artisan('laiatime:sync');
        Notification::assertNothingSent(); // ya estaba en mora y la falla es la misma
    });

    it('es idempotente: una segunda corrida no vuelve a escribir', function () {
        $fake = ltFake(['out' => 'active']);
        ltClient('out', 30);

        $this->artisan('laiatime:sync');
        $this->artisan('laiatime:sync');

        expect($fake->writes)->toBe(['past_due:out']);
    });
});

describe('cambio de instancia del cliente', function () {
    it('reinicia el estado guardado al cambiar el ID de LaiaTime', function () {
        $client = ltClient('vieja', 0, ['laiatime_status' => 'past_due', 'laiatime_suspended_by_system' => true]);

        $this->actingAs(userWithRole('sysadmin'))
            ->put("/clientes/{$client->id}", ['business_name' => $client->business_name, 'laiatime_instance_id' => 'nueva', 'is_active' => true])
            ->assertSessionHasNoErrors();

        $client->refresh();
        expect($client->laiatime_instance_id)->toBe('nueva')->and($client->laiatime_status)->toBeNull()->and($client->laiatime_suspended_by_system)->toBeFalse();
    });
});
