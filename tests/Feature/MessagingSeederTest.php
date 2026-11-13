<?php

use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\MessagingSeeder;

it('el seeder de mensajería deja un chat directo y un hilo con menciones, sin notificaciones', function () {
    $duena = userWithRole('dueno', ['email' => 'direccion@laia.test']);
    $camila = userWithRole('empleado', ['email' => 'camila@laia.test']);
    Client::factory()->create()->users()->attach($camila);

    $this->seed(MessagingSeeder::class);
    $this->seed(MessagingSeeder::class);   // idempotente a nivel de conversaciones

    expect(Conversation::where('type', 'direct')->count())->toBe(1)
        ->and(Conversation::where('type', 'thread')->count())->toBe(1)
        ->and($duena->notifications()->count() + $camila->notifications()->count())->toBe(0);

    // Camila ve ambas en su bandeja; en el chat directo le queda 1 mensaje sin leer.
    $inbox = $this->actingAs($camila)->getJson('/mensajes/api/conversaciones')->assertOk();
    $inbox->assertJsonCount(2, 'conversations');
    expect(collect($inbox->json('conversations'))->firstWhere('type', 'direct')['unread'])->toBe(1);

    // Y las menciones del hilo están validadas contra quienes tienen acceso.
    $thread = Conversation::where('type', 'thread')->first();
    $mentioned = $thread->messages->flatMap(fn ($m) => $m->mentions ?? [])->unique();
    expect($mentioned->every(fn ($id) => $thread->eligibleUsers()->pluck('id')->contains($id)))->toBeTrue();
});

it('no falla si faltan los usuarios de demostración', function () {
    $this->seed(MessagingSeeder::class);

    expect(Conversation::count())->toBe(0)->and(User::count())->toBe(0);
});
