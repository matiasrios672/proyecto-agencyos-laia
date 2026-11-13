<?php

namespace App\Notifications;

use App\Models\TeamPayout;
use App\Support\Money;
use Illuminate\Notifications\Notification;

class PayoutSettled extends Notification
{
    public function __construct(private readonly TeamPayout $payout) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'wallet',
            'title' => 'Liquidación pagada',
            'body' => 'Te liquidaron '.Money::format($this->payout->amount, $this->payout->currency).'.',
            'url' => '/liquidaciones',
        ];
    }
}
