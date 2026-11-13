<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Liquidación pendiente o pagada al empleado por un cobro. */
class TeamPayout extends Model
{
    protected $fillable = [
        'invoice_id', 'client_service_id', 'user_id', 'amount', 'currency', 'calculation', 'status',
        'settled_at', 'settled_by', 'settlement_note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'currency' => Currency::class, 'settled_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ClientService::class, 'client_service_id')->withTrashed();
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pendiente');
    }

    /** Quien tiene payouts.view_own solo ve lo suyo; quien tiene finance.view ve todo. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('finance.view') ? $query : $query->where('user_id', $user->id);
    }
}
