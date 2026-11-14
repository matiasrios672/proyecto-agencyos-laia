<?php

namespace App\Notifications;

use App\Models\TeamPayout;
use App\Support\Money;
use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PayoutSettled extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(private readonly TeamPayout $payout) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', ...$this->mailChannels($notifiable)];
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

    protected function templateKey(): string
    {
        return 'finance.payout_settled';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'importe' => Money::format($this->payout->amount, $this->payout->currency),
            'url' => url('/liquidaciones'),
        ];
    }
}
