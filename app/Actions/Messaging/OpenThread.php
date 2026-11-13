<?php

namespace App\Actions\Messaging;

use App\Models\Client;
use App\Models\Conversation;
use Illuminate\Database\UniqueConstraintViolationException;

/** Abre (o recupera) el hilo contextual de un cliente. Idempotente: hay un solo hilo por cliente. */
class OpenThread
{
    public function handle(Client $client): Conversation
    {
        $attributes = ['type' => Conversation::THREAD, 'subject_type' => 'client', 'subject_id' => $client->id];

        try {
            return Conversation::firstOrCreate($attributes);
        } catch (UniqueConstraintViolationException) {
            return Conversation::where($attributes)->firstOrFail();
        }
    }
}
