<?php

namespace App\Notifications;

use App\Models\TeamPayout;
use App\Support\Money;
use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Aviso al empleado: se cobró un servicio suyo y se generó su liquidación. */
class PayoutGenerated extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(private readonly TeamPayout $payout, private readonly string $clientName) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', ...$this->mailChannels($notifiable)];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'wallet',
            'title' => 'Se generó una liquidación',
            'body' => "Se cobró {$this->clientName}: te corresponden ".Money::format($this->payout->amount, $this->payout->currency).'.',
            'url' => '/liquidaciones',
        ];
    }

    protected function templateKey(): string
    {
        return 'finance.payout_generated';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'cliente' => $this->clientName,
            'importe' => Money::format($this->payout->amount, $this->payout->currency),
            'url' => url('/liquidaciones'),
        ];
    }
}
