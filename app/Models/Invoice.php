<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'number', 'client_id', 'client_service_id', 'description', 'amount', 'currency', 'period', 'issue_date',
        'due_date', 'status', 'paid_at', 'payment_method', 'payment_reference', 'void_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'currency' => Currency::class,
            'status' => InvoiceStatus::class,
            'payment_method' => PaymentMethod::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ClientService::class, 'client_service_id')->withTrashed();
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(TeamPayout::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(ReminderLog::class);
    }

    public function isPending(): bool
    {
        return $this->status === InvoiceStatus::Pendiente;
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_date->lt(today());
    }

    /** Estado visible: un cobro pendiente cuyo vencimiento pasó se muestra como vencido. */
    public function effectiveStatus(): InvoiceStatus
    {
        return $this->isOverdue() ? InvoiceStatus::Vencida : $this->status;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Pendiente->value);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->pending()->whereDate('due_date', '<', today());
    }
}
