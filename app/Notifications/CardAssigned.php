<?php

namespace App\Notifications;

use App\Models\Client;
use App\Models\KanbanCard;
use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Aviso interno: te asignaron una tarjeta del tablero de un cliente. */
class CardAssigned extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(
        private readonly KanbanCard $card,
        private readonly Client $client,
        private readonly string $assignedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', ...$this->mailChannels($notifiable)];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'bell',
            'title' => 'Te asignaron una tarjeta',
            'body' => "{$this->assignedBy} te asignó «{$this->card->title}» en {$this->client->displayName()}.",
            'url' => "/clientes/{$this->client->id}/tablero",
        ];
    }

    protected function templateKey(): string
    {
        return 'assignment.card';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'tarjeta' => $this->card->title,
            'cliente' => $this->client->displayName(),
            'asignado_por' => $this->assignedBy,
            'url' => url("/clientes/{$this->client->id}/tablero"),
        ];
    }
}
