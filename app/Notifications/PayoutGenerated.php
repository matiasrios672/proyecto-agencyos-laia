<?php

namespace App\Notifications;

use App\Models\TeamPayout;
use App\Support\Money;
use Illuminate\Notifications\Notification;

/** Aviso al empleado: se cobró un servicio suyo y se generó su liquidación. */
class PayoutGenerated extends Notification
{
    public function __construct(private readonly TeamPayout $payout, private readonly string $clientName) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
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
}
