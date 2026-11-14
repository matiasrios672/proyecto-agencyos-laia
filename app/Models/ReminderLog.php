<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'type', 'sent_at', 'sent_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Quién lo mandó a mano; null si salió automático. */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
