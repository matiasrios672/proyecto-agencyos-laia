<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Mensajería de demostración: un chat directo y el hilo de un cliente con menciones.
 * Se crea directo en la base (sin eventos ni notificaciones): cada usuario arranca con la campana limpia.
 */
class MessagingSeeder extends Seeder
{
    public function run(): void
    {
        $duena = User::where('email', 'direccion@laia.test')->first();
        $camila = User::where('email', 'camila@laia.test')->first();
        $tomas = User::where('email', 'tomas@laia.test')->first();

        if (! $duena || ! $camila) {
            return;
        }

        // Chat directo Lucía ↔ Camila (Lucía escribió último: a Camila le queda 1 sin leer).
        $direct = Conversation::firstOrCreate(['direct_key' => collect([$duena->id, $camila->id])->sort()->implode('-')], ['type' => Conversation::DIRECT]);
        $direct->participants()->syncWithoutDetaching([$duena->id, $camila->id]);

        $this->say($direct, $camila, 'Hola Lucía, ya tengo la grilla de noviembre de La Espiga lista para revisar.', 125);
        $this->say($direct, $duena, '¡Genial! La reviso hoy. ¿Pudiste confirmar las fotos con ellos?', 110);
        $this->say($direct, $camila, 'Sí, me las mandaron ayer a la noche. Están en la carpeta compartida.', 95);
        $last = $this->say($direct, $duena, 'Perfecto, gracias Cami. Después te paso mis comentarios.', 60);
        $this->markRead($direct, $camila, $last->id - 1);
        $this->markRead($direct, $duena, $last->id);

        // Hilo del primer cliente al que tiene acceso el equipo, con menciones.
        $client = Client::whereHas('users', fn ($q) => $q->whereKey($camila->id))->orderBy('id')->first();
        if (! $client) {
            return;
        }

        $thread = Conversation::firstOrCreate(
            ['subject_type' => 'client', 'subject_id' => $client->id],
            ['type' => Conversation::THREAD],
        );

        $this->say($thread, $camila, "Nota para el equipo: {$client->displayName()} pidió cambiar el tono de los posteos a algo más cercano.", 300);
        $this->say($thread, $duena, '@Camila Sosa perfecto, armá una propuesta de tres posteos de prueba y la vemos el jueves.', 240, [$camila->id]);
        if ($tomas && $client->users()->whereKey($tomas->id)->exists()) {
            $this->say($thread, $camila, '@Tomás Aguirre ¿me ayudás con las piezas gráficas? Necesito tres formatos para historias.', 200, [$tomas->id]);
        }
        $this->say($thread, $camila, '@Lucía Ferrero listo el borrador, lo subo al tablero esta tarde.', 30, [$duena->id]);
    }

    /** @param  list<int>  $mentions */
    private function say(Conversation $conversation, User $author, string $body, int $minutesAgo, array $mentions = []): Message
    {
        $message = $conversation->messages()->create(['user_id' => $author->id, 'body' => $body, 'mentions' => $mentions]);
        $when = now()->subMinutes($minutesAgo);
        $message->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
        $conversation->forceFill(['last_message_at' => $when])->save();

        return $message;
    }

    private function markRead(Conversation $conversation, User $user, int $lastId): void
    {
        $conversation->participants()->syncWithoutDetaching([$user->id => ['last_read_at' => now(), 'last_read_message_id' => $lastId]]);
    }
}
