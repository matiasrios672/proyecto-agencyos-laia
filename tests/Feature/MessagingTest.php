<?php

use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

const MSG_API = '/mensajes/api';

/** Cliente con equipo asignado (para probar el acceso a su hilo). */
function msgClient(array $team = [], array $attrs = []): Client
{
    $client = Client::factory()->create(['trade_name' => null] + $attrs);
    $client->users()->sync(collect($team)->pluck('id'));

    return $client;
}

function msgDirect($test, User $as, User $partner)
{
    return $test->actingAs($as)->postJson(MSG_API.'/directa', ['user_id' => $partner->id]);
}

function msgThread($test, User $as, Client $client)
{
    return $test->actingAs($as)->postJson(MSG_API.'/hilos', ['subject_type' => 'client', 'subject_id' => $client->id]);
}

function msgSend($test, User $as, int $conversationId, string $body = 'Hola', array $mentions = [])
{
    return $test->actingAs($as)->postJson(MSG_API."/conversaciones/{$conversationId}/mensajes", ['body' => $body, 'mentions' => $mentions]);
}

beforeEach(function () {
    $this->duena = userWithRole('dueno', ['name' => 'Lucía Ferrero']);
    $this->admin = userWithRole('sysadmin', ['name' => 'Admin Sys']);
    $this->camila = userWithRole('empleado', ['name' => 'Camila Sosa']);
    $this->tomas = userWithRole('empleado', ['name' => 'Tomás Aguirre']);
});

describe('acceso', function () {
    it('exige sesión', function () {
        $this->get('/mensajes')->assertRedirect('/login');
        $this->getJson(MSG_API.'/conversaciones')->assertUnauthorized();
        $this->postJson(MSG_API.'/directa', ['user_id' => 1])->assertUnauthorized();
    });

    it('exige el permiso messages.use', function () {
        Role::findByName('empleado')->revokePermissionTo('messages.use');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->camila->fresh())->get('/mensajes')->assertForbidden();
        $this->actingAs($this->camila->fresh())->getJson(MSG_API.'/conversaciones')->assertForbidden();
        $this->actingAs($this->camila->fresh())->getJson(MSG_API.'/no-leidos')->assertForbidden();
    });

    it('la página lista solo compañeros activos con permiso y nunca a uno mismo', function () {
        userWithRole('empleado', ['is_active' => false, 'name' => 'Persona de Baja']);

        $this->actingAs($this->camila)->get('/mensajes')->assertOk()->assertInertia(fn ($p) => $p
            // (false: el buscador de páginas de Inertia mira js/Pages, y este proyecto usa js/pages)
            ->component('Messages/Index', false)
            ->where('me.id', $this->camila->id)
            ->where('selected', null)
            ->has('teammates', 3)   // Lucía, Admin, Tomás
            ->where('teammates', fn ($t) => collect($t)->pluck('name')->sort()->values()->all() === ['Admin Sys', 'Lucía Ferrero', 'Tomás Aguirre']));
    });

    it('preselecciona solo una conversación a la que se tiene acceso', function () {
        $propia = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        $ajena = msgDirect($this, $this->tomas, $this->duena)->json('conversation.id');

        $this->actingAs($this->camila)->get("/mensajes?c={$propia}")->assertInertia(fn ($p) => $p->where('selected', $propia));
        $this->actingAs($this->camila)->get("/mensajes?c={$ajena}")->assertInertia(fn ($p) => $p->where('selected', null));
        $this->actingAs($this->camila)->get('/mensajes?c=99999')->assertInertia(fn ($p) => $p->where('selected', null));
    });

    it('el HTML de la página no incluye el contenido de los mensajes', function () {
        $id = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        msgSend($this, $this->camila, $id, 'Contenido-secreto-del-chat');

        $this->actingAs($this->camila)->get("/mensajes?c={$id}")->assertOk()->assertDontSee('Contenido-secreto-del-chat');
    });
});

describe('chat directo', function () {
    it('abre un chat con un compañero', function () {
        msgDirect($this, $this->camila, $this->duena)->assertOk()
            ->assertJsonPath('conversation.type', 'direct')
            ->assertJsonPath('conversation.title', 'Lucía Ferrero')
            ->assertJsonPath('conversation.other.id', $this->duena->id)
            ->assertJsonPath('conversation.unread', 0);

        $conversation = Conversation::firstOrFail();
        expect($conversation->participants->pluck('id')->sort()->values()->all())->toBe(collect([$this->camila->id, $this->duena->id])->sort()->values()->all())
            ->and($conversation->direct_key)->toBe(collect([$this->camila->id, $this->duena->id])->sort()->implode('-'));
    });

    it('es idempotente: un solo chat por par, sin importar quién lo abra', function () {
        $a = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        $b = msgDirect($this, $this->duena, $this->camila)->json('conversation.id');
        $c = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');

        expect($a)->toBe($b)->and($b)->toBe($c)->and(Conversation::count())->toBe(1);
    });

    it('no permite chatear con uno mismo, con inactivos ni con inexistentes', function () {
        $baja = userWithRole('empleado', ['is_active' => false]);

        foreach ([$this->duena->id, $baja->id, 99999] as $id) {
            $this->actingAs($this->duena)->postJson(MSG_API.'/directa', ['user_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        }
        $this->actingAs($this->duena)->postJson(MSG_API.'/directa', [])->assertUnprocessable();

        expect(Conversation::count())->toBe(0);
    });

    it('no permite chatear con quien no tiene permiso de mensajería', function () {
        Role::findByName('empleado')->revokePermissionTo('messages.use');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Los empleados perdieron el permiso: Dirección (que lo conserva) no puede abrirles un chat.
        $this->actingAs($this->duena->fresh())->postJson(MSG_API.'/directa', ['user_id' => $this->tomas->id])->assertUnprocessable();

        expect(Conversation::count())->toBe(0);
    });

    it('envía y recibe mensajes; los propios nunca cuentan como no leídos', function () {
        $id = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');

        msgSend($this, $this->camila, $id, 'Hola Lucía')->assertCreated()
            ->assertJsonPath('message.body', 'Hola Lucía')->assertJsonPath('message.user.name', 'Camila Sosa')
            ->assertJsonPath('message.deleted', false)->assertJsonPath('message.edited', false);

        $show = $this->actingAs($this->duena)->getJson(MSG_API."/conversaciones/{$id}")->assertOk();
        $show->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'Hola Lucía')->assertJsonPath('conversation.unread', 1);

        $this->actingAs($this->camila)->getJson(MSG_API."/conversaciones/{$id}")->assertJsonPath('conversation.unread', 0);
        expect(Conversation::first()->last_message_at)->not->toBeNull();
    });

    it('nadie ajeno puede leer, escribir ni marcar como leído un chat privado (ni el sysadmin)', function () {
        $id = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        $mensaje = msgSend($this, $this->camila, $id, 'Privado')->json('message.id');

        foreach ([$this->tomas, $this->admin] as $intruso) {
            $this->actingAs($intruso)->getJson(MSG_API."/conversaciones/{$id}")->assertForbidden();
            msgSend($this, $intruso, $id, 'Intruso')->assertForbidden();
            $this->actingAs($intruso)->postJson(MSG_API."/conversaciones/{$id}/leer")->assertForbidden();
            $this->actingAs($intruso)->putJson(MSG_API."/mensajes/{$mensaje}", ['body' => 'Editado'])->assertForbidden();
            $this->actingAs($intruso)->deleteJson(MSG_API."/mensajes/{$mensaje}")->assertForbidden();
        }

        expect(Message::count())->toBe(1)->and(Message::first()->body)->toBe('Privado');
    });

    it('la bandeja solo muestra mis conversaciones, con vista previa y no leídos', function () {
        $mia = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        msgDirect($this, $this->tomas, $this->duena);
        msgSend($this, $this->duena, $mia, 'Primero');
        msgSend($this, $this->duena, $mia, "Segundo mensaje\ncon salto");

        $this->actingAs($this->camila)->getJson(MSG_API.'/conversaciones')->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $mia)
            ->assertJsonPath('conversations.0.unread', 2)
            ->assertJsonPath('conversations.0.last.preview', 'Segundo mensaje con salto')
            ->assertJsonPath('conversations.0.last.mine', false)
            ->assertHeader('Cache-Control');
    });
});

describe('validación y seguridad del mensaje', function () {
    beforeEach(fn () => $this->chat = msgDirect($this, $this->camila, $this->duena)->json('conversation.id'));

    it('rechaza mensajes vacíos o solo espacios', function (string $body) {
        msgSend($this, $this->camila, $this->chat, $body)->assertUnprocessable()->assertJsonValidationErrors('body');

        expect(Message::count())->toBe(0);
    })->with(['vacío' => [''], 'espacios' => ['     '], 'saltos' => ["\n\n\t "], 'control' => ["\x00\x07\x1B"]]);

    it('acepta hasta 4000 caracteres y rechaza más', function () {
        msgSend($this, $this->camila, $this->chat, str_repeat('á', 4000))->assertCreated();
        msgSend($this, $this->camila, $this->chat, str_repeat('á', 4001))->assertUnprocessable()->assertJsonValidationErrors('body');

        expect(Message::count())->toBe(1);
    });

    it('quita caracteres de control pero conserva saltos de línea y normaliza CRLF', function () {
        $body = msgSend($this, $this->camila, $this->chat, "Hola\x00\x07 mundo\r\nLínea 2\tcon tab \x1B")->assertCreated()->json('message.body');

        expect($body)->toBe("Hola mundo\nLínea 2\tcon tab");
    });

    it('guarda y devuelve el HTML como texto (el navegador lo muestra escapado)', function () {
        $xss = '<script>alert(1)</script><img src=x onerror=alert(2)> **no es markdown**';

        $response = msgSend($this, $this->camila, $this->chat, $xss)->assertCreated();

        expect($response->json('message.body'))->toBe($xss)
            ->and($response->headers->get('Content-Type'))->toContain('application/json');
        // La API solo habla JSON: nunca devuelve HTML con el contenido incrustado.
        $this->actingAs($this->duena)->getJson(MSG_API."/conversaciones/{$this->chat}")->assertJsonPath('messages.0.body', $xss);
    });

    it('limita la cantidad de mensajes por minuto', function () {
        foreach (range(1, 30) as $i) {
            msgSend($this, $this->camila, $this->chat, "Mensaje {$i}")->assertCreated();
        }

        msgSend($this, $this->camila, $this->chat, 'Uno de más')->assertStatus(429);
        expect(Message::count())->toBe(30);
    });

    it('valida el formato de las menciones', function () {
        msgSend($this, $this->camila, $this->chat, 'Hola', range(1, 21))->assertUnprocessable()->assertJsonValidationErrors('mentions');
        $this->actingAs($this->camila)->postJson(MSG_API."/conversaciones/{$this->chat}/mensajes", ['body' => 'Hola', 'mentions' => ['abc']])
            ->assertUnprocessable()->assertJsonValidationErrors('mentions.0');
        $this->actingAs($this->camila)->postJson(MSG_API."/conversaciones/{$this->chat}/mensajes", ['body' => ['x']])->assertUnprocessable();
    });

    it('una conversación inexistente da 404', function () {
        $this->actingAs($this->camila)->getJson(MSG_API.'/conversaciones/99999')->assertNotFound();
        msgSend($this, $this->camila, 99999)->assertNotFound();
    });
});

describe('hilos por cliente', function () {
    it('abre el hilo quien tiene acceso al cliente: equipo asignado, Dirección y sysadmin', function () {
        $client = msgClient([$this->camila]);

        foreach ([$this->camila, $this->duena, $this->admin] as $persona) {
            msgThread($this, $persona, $client)->assertOk()
                ->assertJsonPath('conversation.type', 'thread')
                ->assertJsonPath('conversation.title', "Hilo · {$client->displayName()}")
                ->assertJsonPath('conversation.subject.url', "/clientes/{$client->id}");
        }

        expect(Conversation::count())->toBe(1);   // idempotente: un hilo por cliente
    });

    it('un empleado NO puede abrir ni leer el hilo de un cliente ajeno (403)', function () {
        $suyo = msgClient([$this->camila]);
        $ajeno = msgClient([$this->tomas]);
        $hiloAjeno = msgThread($this, $this->tomas, $ajeno)->json('conversation.id');
        msgSend($this, $this->tomas, $hiloAjeno, 'Confidencial de Tomás');

        msgThread($this, $this->camila, $ajeno)->assertForbidden();
        $this->actingAs($this->camila)->getJson(MSG_API."/conversaciones/{$hiloAjeno}")->assertForbidden();
        msgSend($this, $this->camila, $hiloAjeno, 'Me meto')->assertForbidden();
        $this->actingAs($this->camila)->postJson(MSG_API."/conversaciones/{$hiloAjeno}/leer")->assertForbidden();
        msgThread($this, $this->camila, $suyo)->assertOk();

        expect(Message::count())->toBe(1);
    });

    it('da 403 igual para clientes inexistentes o eliminados (no se pueden explorar ids)', function () {
        $eliminado = msgClient([$this->camila]);
        $eliminado->delete();

        msgThread($this, $this->camila, $eliminado)->assertForbidden();
        $this->actingAs($this->camila)->postJson(MSG_API.'/hilos', ['subject_type' => 'client', 'subject_id' => 99999])->assertForbidden();
        $this->actingAs($this->admin)->postJson(MSG_API.'/hilos', ['subject_type' => 'client', 'subject_id' => 99999])->assertForbidden();
    });

    it('solo acepta sujetos de la lista blanca', function () {
        foreach (['user', 'App\\Models\\User', 'invoice', ''] as $type) {
            $this->actingAs($this->duena)->postJson(MSG_API.'/hilos', ['subject_type' => $type, 'subject_id' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('subject_type');
        }
        expect(Conversation::count())->toBe(0);
    });

    it('al sacar a alguien del equipo del cliente pierde el acceso al hilo', function () {
        $client = msgClient([$this->camila]);
        $id = msgThread($this, $this->camila, $client)->json('conversation.id');
        msgSend($this, $this->duena, $id, 'Hola equipo')->assertCreated();
        $this->actingAs($this->camila)->getJson(MSG_API."/conversaciones/{$id}")->assertOk();

        $client->users()->detach($this->camila->id);

        $this->actingAs($this->camila)->getJson(MSG_API."/conversaciones/{$id}")->assertForbidden();
        $this->actingAs($this->camila)->getJson(MSG_API.'/conversaciones')->assertJsonCount(0, 'conversations');
    });

    it('un usuario dado de baja pierde el acceso a todo', function () {
        $id = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        $this->camila->update(['is_active' => false]);

        // El middleware de cuenta activa lo desconecta antes de llegar al controlador.
        $this->actingAs($this->camila->fresh())->getJson(MSG_API."/conversaciones/{$id}")->assertRedirect('/login');
        $this->assertGuest();
        // Y aunque el middleware no estuviera, el modelo tampoco le da acceso.
        expect(Conversation::find($id)->isAccessibleBy($this->camila->fresh()))->toBeFalse()
            ->and(\App\Support\Messaging\Inbox::summaries($this->camila->fresh()))->toBe([]);
    });

    it('la bandeja muestra el hilo solo con mensajes y solo a quien ve al cliente', function () {
        $client = msgClient([$this->camila]);
        $id = msgThread($this, $this->camila, $client)->json('conversation.id');

        // Sin mensajes todavía no aparece en ninguna bandeja.
        $this->actingAs($this->camila)->getJson(MSG_API.'/conversaciones')->assertJsonCount(0, 'conversations');

        msgSend($this, $this->camila, $id, 'Primera nota del cliente');

        $this->actingAs($this->camila)->getJson(MSG_API.'/conversaciones')->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.id', $id);
        $this->actingAs($this->duena)->getJson(MSG_API.'/conversaciones')->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.unread', 1);
        $this->actingAs($this->admin)->getJson(MSG_API.'/conversaciones')->assertJsonCount(1, 'conversations');
        $this->actingAs($this->tomas)->getJson(MSG_API.'/conversaciones')->assertJsonCount(0, 'conversations');
    });

    it('solo se puede mencionar a quien tiene acceso al cliente', function () {
        $client = msgClient([$this->camila]);
        $id = msgThread($this, $this->camila, $client)->json('conversation.id');

        $names = collect($this->actingAs($this->camila)->getJson(MSG_API."/conversaciones/{$id}")->json('mentionables'))->pluck('name')->sort()->values()->all();

        expect($names)->toBe(['Admin Sys', 'Lucía Ferrero'])   // sin Tomás (no tiene el cliente) y sin la propia Camila
            ->and($names)->not->toContain('Tomás Aguirre');
    });
});

describe('menciones', function () {
    beforeEach(function () {
        $this->client = msgClient([$this->camila]);
        $this->hilo = msgThread($this, $this->camila, $this->client)->json('conversation.id');
    });

    it('guarda las menciones válidas y notifica a cada persona mencionada', function () {
        $response = msgSend($this, $this->camila, $this->hilo, '@Lucía Ferrero mirá esto', [$this->duena->id])->assertCreated();

        $response->assertJsonPath('message.mentions.0.id', $this->duena->id)->assertJsonPath('message.mentions.0.name', 'Lucía Ferrero');
        $n = $this->duena->notifications()->first();
        expect($n->type)->toBe(MentionedInMessage::class)
            ->and($n->data['title'])->toBe('Te mencionaron')
            ->and($n->data['url'])->toBe("/mensajes?c={$this->hilo}")
            ->and($n->data['icon'])->toBe('message')
            ->and($n->data['body'])->toStartWith('Camila Sosa: @Lucía Ferrero');
        expect($this->camila->notifications()->count())->toBe(0);
    });

    it('ignora menciones de personas sin acceso: ni se guardan ni se notifica', function () {
        msgSend($this, $this->camila, $this->hilo, '@Tomás secreto del cliente', [$this->tomas->id, 99999])->assertCreated()
            ->assertJsonPath('message.mentions', []);

        expect($this->tomas->notifications()->count())->toBe(0)->and(Message::first()->mentions)->toBe([]);
    });

    it('ignora la mención a uno mismo y los repetidos', function () {
        msgSend($this, $this->camila, $this->hilo, 'Nota', [$this->camila->id, $this->duena->id, $this->duena->id, (string) $this->duena->id])->assertCreated();

        expect(Message::first()->mentions)->toBe([$this->duena->id])
            ->and($this->camila->notifications()->count())->toBe(0)
            ->and($this->duena->notifications()->count())->toBe(1);
    });

    it('al editar solo se avisa a quienes se mencionan por primera vez', function () {
        $id = msgSend($this, $this->camila, $this->hilo, 'Hola @Lucía', [$this->duena->id])->json('message.id');
        expect($this->duena->notifications()->count())->toBe(1);

        $this->actingAs($this->camila)->putJson(MSG_API."/mensajes/{$id}", ['body' => 'Hola @Lucía y @Admin', 'mentions' => [$this->duena->id, $this->admin->id]])
            ->assertOk()->assertJsonPath('message.edited', true)->assertJsonCount(2, 'message.mentions');

        expect($this->duena->notifications()->count())->toBe(1)    // no se repite
            ->and($this->admin->notifications()->count())->toBe(1);
    });

    it('en un chat directo solo se puede mencionar a la otra persona', function () {
        $chat = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');

        msgSend($this, $this->camila, $chat, 'Hola', [$this->duena->id, $this->tomas->id, $this->admin->id])->assertCreated();

        expect(Message::first()->mentions)->toBe([$this->duena->id])->and($this->tomas->notifications()->count())->toBe(0);
    });

    it('un mensaje eliminado no entrega su contenido ni sus menciones', function () {
        $id = msgSend($this, $this->camila, $this->hilo, 'Dato sensible @Lucía', [$this->duena->id])->json('message.id');
        $this->actingAs($this->camila)->deleteJson(MSG_API."/mensajes/{$id}")->assertOk();

        $this->actingAs($this->duena)->getJson(MSG_API."/conversaciones/{$this->hilo}")
            ->assertJsonPath('messages.0.deleted', true)->assertJsonPath('messages.0.body', null)->assertJsonPath('messages.0.mentions', []);
    });
});

describe('no leídos', function () {
    beforeEach(function () {
        $this->chat = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        $this->unread = fn (User $u) => $this->actingAs($u)->getJson(MSG_API.'/no-leidos')->json('unread');
    });

    it('cuenta solo lo que escribieron otros y baja a cero al marcar como leído', function () {
        msgSend($this, $this->duena, $this->chat, 'Uno');
        msgSend($this, $this->duena, $this->chat, 'Dos');
        msgSend($this, $this->camila, $this->chat, 'Mi respuesta');

        expect(($this->unread)($this->camila))->toBe(2)->and(($this->unread)($this->duena))->toBe(1);

        $this->actingAs($this->camila)->postJson(MSG_API."/conversaciones/{$this->chat}/leer")->assertOk();

        expect(($this->unread)($this->camila))->toBe(0)->and(($this->unread)($this->duena))->toBe(1);
    });

    it('un mensaje que llega después de leer vuelve a contar (aun en el mismo segundo)', function () {
        msgSend($this, $this->duena, $this->chat, 'Uno');
        $this->actingAs($this->camila)->postJson(MSG_API."/conversaciones/{$this->chat}/leer");
        msgSend($this, $this->duena, $this->chat, 'Dos');   // mismo segundo: el cálculo es por id, no por hora

        expect(($this->unread)($this->camila))->toBe(1);
    });

    it('no cuenta los mensajes eliminados', function () {
        $id = msgSend($this, $this->duena, $this->chat, 'Lo borro')->json('message.id');
        expect(($this->unread)($this->camila))->toBe(1);

        $this->actingAs($this->duena)->deleteJson(MSG_API."/mensajes/{$id}")->assertOk();

        expect(($this->unread)($this->camila))->toBe(0);
    });

    it('suma los no leídos de chats e hilos', function () {
        $client = msgClient([$this->camila]);
        $hilo = msgThread($this, $this->camila, $client)->json('conversation.id');
        msgSend($this, $this->duena, $this->chat, 'En el chat');
        msgSend($this, $this->duena, $hilo, 'En el hilo');
        msgSend($this, $this->duena, $hilo, 'Otro en el hilo');

        expect(($this->unread)($this->camila))->toBe(3);
        $this->actingAs($this->camila)->getJson(MSG_API.'/conversaciones')->assertJsonPath('conversations.0.unread', fn ($n) => in_array($n, [1, 2], true));
    });

    it('abrir un hilo por primera vez crea la fila de lectura del usuario', function () {
        $client = msgClient([$this->camila]);
        $hilo = msgThread($this, $this->duena, $client)->json('conversation.id');
        msgSend($this, $this->duena, $hilo, 'Hola');

        $this->actingAs($this->camila)->postJson(MSG_API."/conversaciones/{$hilo}/leer")->assertOk();

        $row = Conversation::find($hilo)->participants()->whereKey($this->camila->id)->first();
        expect($row->pivot->last_read_message_id)->toBe(Message::max('id'))->and($row->pivot->last_read_at)->not->toBeNull();
        expect(($this->unread)($this->camila))->toBe(0);
    });
});

describe('editar y borrar', function () {
    beforeEach(function () {
        $this->chat = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        $this->mensaje = msgSend($this, $this->camila, $this->chat, 'Original')->json('message.id');
    });

    it('el autor edita su mensaje', function () {
        $this->actingAs($this->camila)->putJson(MSG_API."/mensajes/{$this->mensaje}", ['body' => 'Corregido'])
            ->assertOk()->assertJsonPath('message.body', 'Corregido')->assertJsonPath('message.edited', true);

        expect(Message::find($this->mensaje)->edited_at)->not->toBeNull();
    });

    it('solo el autor edita o borra: ni la otra persona ni el sysadmin', function () {
        foreach ([$this->duena, $this->admin, $this->tomas] as $otro) {
            $this->actingAs($otro)->putJson(MSG_API."/mensajes/{$this->mensaje}", ['body' => 'Hackeado'])->assertForbidden();
            $this->actingAs($otro)->deleteJson(MSG_API."/mensajes/{$this->mensaje}")->assertForbidden();
        }

        expect(Message::find($this->mensaje)->body)->toBe('Original');
    });

    it('un mensaje eliminado se muestra como eliminado y ya no se puede editar ni borrar', function () {
        $this->actingAs($this->camila)->deleteJson(MSG_API."/mensajes/{$this->mensaje}")->assertOk();

        $this->actingAs($this->duena)->getJson(MSG_API."/conversaciones/{$this->chat}")
            ->assertJsonPath('messages.0.deleted', true)->assertJsonPath('messages.0.body', null)->assertJsonPath('messages.0.edited', false);
        $this->actingAs($this->camila)->putJson(MSG_API."/mensajes/{$this->mensaje}", ['body' => 'Revivir'])->assertNotFound();
        $this->actingAs($this->camila)->deleteJson(MSG_API."/mensajes/{$this->mensaje}")->assertNotFound();
        // Sigue en la base (borrado lógico) para auditoría.
        expect(Message::withTrashed()->find($this->mensaje))->not->toBeNull();
    });

    it('la vista previa de la bandeja no muestra mensajes eliminados', function () {
        msgSend($this, $this->camila, $this->chat, 'Visible');
        $borrado = msgSend($this, $this->camila, $this->chat, 'Este se borra')->json('message.id');
        $this->actingAs($this->camila)->deleteJson(MSG_API."/mensajes/{$borrado}");

        $this->actingAs($this->duena)->getJson(MSG_API.'/conversaciones')->assertJsonPath('conversations.0.last.preview', 'Visible');
    });

    it('valida el cuerpo al editar', function () {
        $this->actingAs($this->camila)->putJson(MSG_API."/mensajes/{$this->mensaje}", ['body' => '   '])->assertUnprocessable();
        $this->actingAs($this->camila)->putJson(MSG_API."/mensajes/{$this->mensaje}", ['body' => str_repeat('x', 4001)])->assertUnprocessable();

        expect(Message::find($this->mensaje)->body)->toBe('Original');
    });

    it('quien perdió el acceso al hilo no puede editar ni borrar ni sus propios mensajes', function () {
        $client = msgClient([$this->camila]);
        $hilo = msgThread($this, $this->camila, $client)->json('conversation.id');
        $propio = msgSend($this, $this->camila, $hilo, 'Mío')->json('message.id');
        $client->users()->detach($this->camila->id);

        $this->actingAs($this->camila)->putJson(MSG_API."/mensajes/{$propio}", ['body' => 'Editado'])->assertForbidden();
        $this->actingAs($this->camila)->deleteJson(MSG_API."/mensajes/{$propio}")->assertForbidden();
    });
});

describe('paginación', function () {
    it('trae los últimos 30 mensajes y permite pedir los anteriores', function () {
        $chat = msgDirect($this, $this->camila, $this->duena)->json('conversation.id');
        foreach (range(1, 35) as $i) {
            Message::create(['conversation_id' => $chat, 'user_id' => $this->camila->id, 'body' => "Mensaje {$i}"]);
        }

        $first = $this->actingAs($this->camila)->getJson(MSG_API."/conversaciones/{$chat}")->assertOk();
        $first->assertJsonCount(30, 'messages')->assertJsonPath('has_more', true)
            ->assertJsonPath('messages.0.body', 'Mensaje 6')->assertJsonPath('messages.29.body', 'Mensaje 35');

        $firstId = $first->json('messages.0.id');
        $older = $this->actingAs($this->camila)->getJson(MSG_API."/conversaciones/{$chat}?before={$firstId}")->assertOk();
        $older->assertJsonCount(5, 'messages')->assertJsonPath('has_more', false)
            ->assertJsonPath('messages.0.body', 'Mensaje 1')->assertJsonPath('messages.4.body', 'Mensaje 5');
    });
});

describe('transformaciones de texto', function () {
    it('SendMessage::clean y validMentions', function () {
        expect(\App\Actions\Messaging\SendMessage::clean("  hola\x00 \r\n mundo  "))->toBe("hola \n mundo");
        expect(fn () => \App\Actions\Messaging\SendMessage::clean("\x00\n"))->toThrow(\Illuminate\Validation\ValidationException::class);

        $author = User::factory()->make(['id' => 5]);
        expect(\App\Actions\Messaging\SendMessage::validMentions([1, 2, 5, 9], $author, ['2', 2, 5, 7, 'x', 9]))->toBe([2, 9]);
    });
});
