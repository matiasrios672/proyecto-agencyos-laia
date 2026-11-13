<?php

namespace App\Actions\Finance;

use App\Enums\AccountStatus;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** Envía un comunicado a su audiencia (todos los clientes activos, por estado de cuenta, o una selección). */
class SendAnnouncement
{
    /** @return array{sent: int, without_email: int} */
    public function handle(Announcement $announcement, User $actor): array
    {
        $queue = [];
        $withoutEmail = 0;

        DB::transaction(function () use ($announcement, $actor, &$queue, &$withoutEmail) {
            // Bloqueo de fila: un doble clic no puede enviar el comunicado dos veces.
            $announcement = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();

            if ($announcement->isSent()) {
                throw ValidationException::withMessages(['announcement' => 'Este comunicado ya fue enviado.']);
            }

            $clients = $this->audience($announcement);
            if ($clients->isEmpty()) {
                throw ValidationException::withMessages(['announcement' => 'La audiencia elegida no tiene clientes.']);
            }

            foreach ($clients as $client) {
                $hasEmail = filled($client->email);
                $announcement->recipients()->create([
                    'client_id' => $client->id,
                    'email' => $client->email,
                    'status' => $hasEmail ? 'enviado' : 'sin_email',
                    'sent_at' => $hasEmail ? now() : null,
                ]);
                $hasEmail ? $queue[] = $client : $withoutEmail++;
            }

            $announcement->forceFill(['status' => 'enviado', 'sent_at' => now()])->save();

            activity('finance')->performedOn($announcement)->causedBy($actor)
                ->withProperties(['recipients' => count($queue), 'without_email' => $withoutEmail])
                ->log('Comunicado enviado');
        });

        // Los correos se encolan recién cuando el envío quedó confirmado en la base.
        foreach ($queue as $client) {
            Mail::to($client->email)->send(new AnnouncementMail($announcement->refresh(), $client->displayName()));
        }

        return ['sent' => count($queue), 'without_email' => $withoutEmail];
    }

    /** @return Collection<int, Client> */
    public function audience(Announcement $announcement): Collection
    {
        $query = Client::query()->where('is_active', true)->orderBy('business_name');

        return match ($announcement->audience) {
            'estado' => $query->where('account_status', $announcement->audience_value['status'] ?? AccountStatus::Deuda->value)->get(),
            'clientes' => $query->whereIn('id', $announcement->audience_value['client_ids'] ?? [])->get(),
            default => $query->get(),
        };
    }
}
