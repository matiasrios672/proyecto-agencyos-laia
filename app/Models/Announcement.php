<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    protected $fillable = ['subject', 'body', 'audience', 'audience_value', 'status', 'sent_at', 'created_by'];

    protected function casts(): array
    {
        return ['audience_value' => 'array', 'sent_at' => 'datetime'];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSent(): bool
    {
        return $this->status === 'enviado';
    }
}
