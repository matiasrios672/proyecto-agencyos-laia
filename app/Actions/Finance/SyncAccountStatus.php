<?php

namespace App\Actions\Finance;

use App\Enums\AccountStatus;
use App\Models\Client;
use App\Support\Settings;

/**
 * El semáforo del cliente se deriva de sus cobros (ya no se edita a mano):
 *   deuda      → tiene algún cobro pendiente con el vencimiento pasado
 *   por_vencer → tiene un cobro pendiente que vence dentro de la ventana de aviso (dunning.reminder_before_days)
 *   al_dia     → todo lo demás
 */
class SyncAccountStatus
{
    public function forClient(Client $client): AccountStatus
    {
        $window = max(0, (int) Settings::get('dunning.reminder_before_days', '3'));
        $pending = $client->invoices()->pending();

        $status = match (true) {
            (clone $pending)->whereDate('due_date', '<', today())->exists() => AccountStatus::Deuda,
            (clone $pending)->whereDate('due_date', '<=', today()->addDays($window))->exists() => AccountStatus::PorVencer,
            default => AccountStatus::AlDia,
        };

        if ($client->account_status !== $status) {
            $client->forceFill(['account_status' => $status])->save();
        }

        return $status;
    }

    /** @return int cantidad de clientes cuyo estado cambió */
    public function forAll(): int
    {
        $changed = 0;

        Client::query()->where('is_active', true)->each(function (Client $client) use (&$changed) {
            $before = $client->account_status;
            if ($this->forClient($client) !== $before) {
                $changed++;
            }
        });

        return $changed;
    }
}
