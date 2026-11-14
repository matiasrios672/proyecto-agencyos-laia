<?php

use App\Events\InboxUpdated;
use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\ClientAssigned;
use App\Notifications\MentionedInMessage;
use App\Notifications\PayoutGenerated;
use App\Notifications\PayoutSettled;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->duena = userWithRole('dueno', ['name' => 'Lucía Ferrero']);
    $this->admin = userWithRole('sysadmin');
    $this->camila = userWithRole('empleado', ['name' => 'Camila Sosa']);
    $this->tomas = userWithRole('empleado', ['name' => 'Tomás Aguirre']);
    $this->client = Client::factory()->create(['trade_name' => null]);
    $this->client->users()->attach($this->camila);
    $this->hilo = Conversation::create(['type' => 'thread', 'subject_type' => 'client', 'subject_id' => $this->client->id]);
});

describe('eventos de broadcast', function () {
    it('un mensaje nuevo viaja por el canal privado de su conversación con lo mínimo necesario', function () {
        Event::fake([MessageSent::class, InboxUpdated::class]);

        msgSend($this, $this->camila, $this->hilo->id, 'Hola equipo')->assertCreated();

        Event::assertDispatched(MessageSent::class, function (MessageSent $e) {
            expect($e->broadcastOn()[0]->name)->toBe("private-conversations.{$this->hilo->id}")
                ->and($e->broadcastAs())->toBe('message.sent')
                ->and(array_keys($e->broadcastWith()))->toBe(['message'])
                ->and(array_keys($e->broadcastWith()['message']))->toBe(['id', 'conversation_id', 'user', 'body', 'deleted', 'mentions', 'edited', 'created_at'])
                ->and(array_keys($e->broadcastWith()['message']['user']))->toBe(['id', 'name']);

            return $e->broadcastWith()['message']['body'] === 'Hola equipo';
        });
    });

    it('la pista de bandeja llega solo a quienes tienen acceso, nunca al autor, y no lleva contenido', function () {
        Event::fake([MessageSent::class, InboxUpdated::class]);

        msgSend($this, $this->camila, $this->hilo->id, 'Texto que no debe filtrarse')->assertCreated();

        $destinatarios = collect(Event::dispatched(InboxUpdated::class))->map(fn ($args) => $args[0]->userId)->sort()->values()->all();
        expect($destinatarios)->toBe(collect([$this->duena->id, $this->admin->id])->sort()->values()->all())   // sin Camila (autora) ni Tomás (sin acceso)
            ->and($destinatarios)->not->toContain($this->tomas->id);

        Event::assertDispatched(InboxUpdated::class, function (InboxUpdated $e) {
            expect($e->broadcastOn()[0]->name)->toBe("private-App.Models.User.{$e->userId}")
                ->and($e->broadcastAs())->toBe('inbox.updated')
                ->and($e->broadcastWith())->toBe(['conversation_id' => $this->hilo->id]);

            return true;
        });
    });

    it('en un chat directo la pista va solo a la otra persona', function () {
        $chat = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        Event::fake([MessageSent::class, InboxUpdated::class]);

        msgSend($this, $this->camila, $chat, 'Hola')->assertCreated();

        $ids = collect(Event::dispatched(InboxUpdated::class))->map(fn ($args) => $args[0]->userId)->all();
        expect($ids)->toBe([$this->duena->id]);
    });

    it('editar y borrar difunden sin filtrar de más', function () {
        $id = msgSend($this, $this->camila, $this->hilo->id, 'Original')->json('message.id');
        Event::fake([MessageUpdated::class, MessageDeleted::class, InboxUpdated::class]);

        $this->actingAs($this->camila)->putJson("/mensajes/api/mensajes/{$id}", ['body' => 'Editado'])->assertOk();
        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $e) => $e->broadcastAs() === 'message.updated'
            && $e->broadcastWith()['message']['body'] === 'Editado' && $e->broadcastWith()['message']['edited'] === true);

        $this->actingAs($this->camila)->deleteJson("/mensajes/api/mensajes/{$id}")->assertOk();
        Event::assertDispatched(MessageDeleted::class, function (MessageDeleted $e) use ($id) {
            expect($e->broadcastWith())->toBe(['conversation_id' => $this->hilo->id, 'id' => $id])   // solo ids: nada del contenido
                ->and($e->broadcastOn()[0]->name)->toBe("private-conversations.{$this->hilo->id}");

            return true;
        });
    });

    it('marcar como leído avisa a las demás pestañas de la misma persona', function () {
        msgSend($this, $this->duena, $this->hilo->id, 'Hola');
        Event::fake([InboxUpdated::class]);

        $this->actingAs($this->camila)->postJson("/mensajes/api/conversaciones/{$this->hilo->id}/leer")->assertOk();

        Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $e) => $e->userId === $this->camila->id && $e->conversationId === $this->hilo->id);
    });

    it('no se difunde nada cuando el pedido es rechazado', function () {
        Event::fake([MessageSent::class, InboxUpdated::class]);

        msgSend($this, $this->tomas, $this->hilo->id, 'Sin acceso')->assertForbidden();
        msgSend($this, $this->camila, $this->hilo->id, '   ')->assertUnprocessable();

        Event::assertNotDispatched(MessageSent::class);
        Event::assertNotDispatched(InboxUpdated::class);
    });
});

describe('autorización de canales (/broadcasting/auth)', function () {
    beforeEach(function () {
        // En los tests el driver es "null": se usa el de Reverb para ejercitar la autorización real de canales.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        // Broadcast::channel() registra en el driver activo al cargar el archivo (en tests era "null"): se vuelve a cargar con Reverb.
        require base_path('routes/channels.php');
        $this->auth = fn (?User $as, string $channel) => ($as ? $this->actingAs($as) : $this)
            ->postJson('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678']);
    });

    it('cada persona autoriza solo su propio canal de notificaciones', function () {
        ($this->auth)($this->camila, "private-App.Models.User.{$this->camila->id}")->assertOk()->assertJsonStructure(['auth']);
        ($this->auth)($this->camila, "private-App.Models.User.{$this->tomas->id}")->assertForbidden();
        ($this->auth)($this->admin, "private-App.Models.User.{$this->camila->id}")->assertForbidden();
    });

    it('el canal de un hilo lo autoriza quien tiene acceso al cliente', function () {
        foreach ([$this->camila, $this->duena, $this->admin] as $persona) {
            ($this->auth)($persona, "private-conversations.{$this->hilo->id}")->assertOk();
        }
        ($this->auth)($this->tomas, "private-conversations.{$this->hilo->id}")->assertForbidden();
    });

    it('el canal de un chat directo lo autorizan solo sus dos participantes', function () {
        $chat = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');

        ($this->auth)($this->camila, "private-conversations.{$chat}")->assertOk();
        ($this->auth)($this->duena, "private-conversations.{$chat}")->assertOk();
        ($this->auth)($this->tomas, "private-conversations.{$chat}")->assertForbidden();
        ($this->auth)($this->admin, "private-conversations.{$chat}")->assertForbidden();
    });

    it('rechaza invitados, usuarios dados de baja, conversaciones inexistentes y canales desconocidos', function () {
        ($this->auth)(null, "private-conversations.{$this->hilo->id}")->assertForbidden();
        ($this->auth)(null, "private-App.Models.User.{$this->camila->id}")->assertForbidden();
        ($this->auth)($this->camila, 'private-conversations.99999')->assertForbidden();
        ($this->auth)($this->camila, 'private-otro.canal')->assertForbidden();
        ($this->auth)($this->camila, 'presence-conversations.1')->assertForbidden();

    });

    it('un usuario dado de baja no puede autorizar ningún canal (lo saca el middleware de cuenta activa)', function () {
        $this->camila->update(['is_active' => false]);

        ($this->auth)($this->camila->fresh(), "private-conversations.{$this->hilo->id}")->assertRedirect('/login');
        ($this->auth)($this->camila->fresh(), "private-App.Models.User.{$this->camila->id}")->assertRedirect('/login');
        $this->assertGuest();
    });

    it('al perder el acceso al cliente se deja de autorizar el canal del hilo', function () {
        ($this->auth)($this->camila, "private-conversations.{$this->hilo->id}")->assertOk();

        $this->client->users()->detach($this->camila->id);

        ($this->auth)($this->camila->fresh(), "private-conversations.{$this->hilo->id}")->assertForbidden();
    });
});

describe('notificaciones en tiempo real', function () {
    it('todas las notificaciones guardan en base y también se difunden', function () {
        $message = new App\Models\Message(['body' => 'x']);

        foreach ([
            new ClientAssigned($this->client, 'Lucía'),
            new MentionedInMessage($message, 'Lucía', 1),
        ] as $notification) {
            expect($notification->via($this->camila))->toContain('database', 'broadcast');
        }

        $payout = new App\Models\TeamPayout(['amount' => 100, 'currency' => 'ARS']);
        foreach ([new PayoutGenerated($payout, 'Cliente'), new PayoutSettled($payout)] as $notification) {
            expect($notification->via($this->camila))->toContain('database', 'broadcast');
        }
    });

    it('al notificar se dispara el evento de difusión por el canal privado del usuario', function () {
        Event::fake([BroadcastNotificationCreated::class]);

        $this->camila->notify(new ClientAssigned($this->client, 'Lucía'));

        Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $e) {
            expect($e->broadcastOn()[0]->name)->toBe("private-App.Models.User.{$this->camila->id}")
                ->and($e->broadcastWith())->toMatchArray(['title' => 'Te asignaron un cliente', 'icon' => 'building'])
                ->and($e->broadcastWith())->toHaveKeys(['id', 'type', 'body', 'url']);

            return true;
        });
        // Y se sigue guardando en la base (la campana la lista aunque el tiempo real falle).
        expect($this->camila->notifications()->count())->toBe(1);
    });

    it('una mención difunde la notificación solo a quien fue mencionado', function () {
        Event::fake([BroadcastNotificationCreated::class]);

        msgSend($this, $this->camila, $this->hilo->id, '@Lucía mirá', [$this->duena->id, $this->tomas->id])->assertCreated();

        Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);
        Event::assertDispatched(BroadcastNotificationCreated::class, fn (BroadcastNotificationCreated $e) => $e->notifiable->is($this->duena)
            && $e->broadcastWith()['icon'] === 'message' && str_starts_with($e->broadcastWith()['url'], '/mensajes?c='));
    });

    it('la API de notificaciones existente sigue listando lo nuevo (respaldo si falla el tiempo real)', function () {
        msgSend($this, $this->camila, $this->hilo->id, '@Lucía mirá', [$this->duena->id])->assertCreated();

        $this->actingAs($this->duena)->getJson('/notificaciones')->assertOk()
            ->assertJsonPath('unread', 1)->assertJsonPath('items.0.icon', 'message')->assertJsonPath('items.0.title', 'Te mencionaron');
    });
});
