<?php

namespace App\Actions\Finance;

use App\Models\TeamPayout;
use App\Models\User;
use App\Notifications\PayoutSettled;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Marca liquidaciones como pagadas al empleado. Cada una se liquida una sola vez. */
class SettlePayouts
{
    /**
     * @param  list<int>  $ids
     * @return int cantidad liquidada
     */
    public function handle(array $ids, User $actor, ?string $note = null): int
    {
        $settled = collect();

        DB::transaction(function () use ($ids, $actor, $note, &$settled) {
            $payouts = TeamPayout::whereIn('id', $ids)->lockForUpdate()->get();

            if ($payouts->isEmpty()) {
                throw ValidationException::withMessages(['ids' => 'No hay liquidaciones para marcar.']);
            }
            if ($payouts->contains(fn (TeamPayout $p) => $p->status !== 'pendiente')) {
                throw ValidationException::withMessages(['ids' => 'Alguna liquidación ya estaba pagada. Actualizá la lista.']);
            }

            foreach ($payouts as $payout) {
                $payout->forceFill([
                    'status' => 'liquidada', 'settled_at' => now(), 'settled_by' => $actor->id, 'settlement_note' => $note,
                ])->save();
            }

            activity('finance')->causedBy($actor)
                ->withProperties(['payouts' => $payouts->pluck('id'), 'total_by_currency' => $payouts->groupBy('currency')->map->sum('amount')])
                ->log('Liquidaciones marcadas como pagadas');

            $settled = $payouts;
        });

        $settled->load('user')->each(fn (TeamPayout $p) => $p->user->notify(new PayoutSettled($p)));

        return $settled->count();
    }
}
