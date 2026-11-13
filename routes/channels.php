<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Notificaciones y pistas de bandeja: cada persona solo escucha su propio canal.
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->is_active && $user->id === $id);

// Mensajes en vivo de una conversación: solo quien tiene acceso (misma regla que la API, no una copia distinta).
Broadcast::channel('conversations.{id}', function (User $user, int $id) {
    $conversation = Conversation::find($id);

    return $conversation !== null && $conversation->isAccessibleBy($user);
});
