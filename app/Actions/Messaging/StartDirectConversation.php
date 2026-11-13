<?php

namespace App\Actions\Messaging;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Abre (o recupera) el chat directo entre dos personas. Idempotente: hay una sola conversación por par. */
class StartDirectConversation
{
    public function handle(User $a, User $b): Conversation
    {
        $key = collect([$a->id, $b->id])->sort()->implode('-');

        try {
            return DB::transaction(function () use ($a, $b, $key) {
                $conversation = Conversation::firstOrCreate(['direct_key' => $key], ['type' => Conversation::DIRECT]);
                $conversation->participants()->syncWithoutDetaching([$a->id, $b->id]);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos pedidos simultáneos para el mismo par: gana el primero y el segundo lo recupera.
            return Conversation::where('direct_key', $key)->firstOrFail();
        }
    }
}
