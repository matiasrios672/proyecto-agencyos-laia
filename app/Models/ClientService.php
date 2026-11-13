<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\Frequency;
use App\Enums\SplitRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Servicio contratado por un cliente: define monto, frecuencia, vencimiento, empleado y regla de reparto. */
class ClientService extends Model
{
    use SoftDeletes;

    public const STATUSES = ['activo' => 'Activo', 'pausado' => 'Pausado', 'cancelado' => 'Cancelado'];

    protected $fillable = [
        'client_id', 'quote_item_id', 'name', 'description', 'amount', 'currency', 'frequency', 'due_day',
        'assigned_user_id', 'split_rule', 'split_percent', 'split_amount', 'status', 'started_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'currency' => Currency::class,
            'frequency' => Frequency::class,
            'split_rule' => SplitRule::class,
            'split_percent' => 'decimal:2',
            'split_amount' => 'integer',
            'due_day' => 'integer',
            'started_at' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** Parte del empleado sobre un importe cobrado (en centavos). Nunca supera lo cobrado. */
    public function employeeShare(int $collected): int
    {
        if ($this->assigned_user_id === null) {
            return 0;
        }

        $share = match ($this->split_rule) {
            SplitRule::Porcentaje => (int) round($collected * ((float) $this->split_percent) / 100),
            SplitRule::MontoFijo => (int) $this->split_amount,
            SplitRule::Agencia100 => 0,
        };

        return max(0, min($share, $collected));
    }
}
