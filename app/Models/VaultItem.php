<?php

namespace App\Models;

use App\Enums\VaultCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultItem extends Model
{
    protected $fillable = ['client_id', 'category', 'label', 'url', 'username', 'secret', 'notes', 'created_by'];

    /** Nunca se serializan: solo salen por el endpoint de revelado, que deja registro de auditoría. */
    protected $hidden = ['secret', 'notes'];

    protected function casts(): array
    {
        return [
            'category' => VaultCategory::class,
            // El cast `encrypted` usa Crypt::encryptString() / decryptString().
            'secret' => 'encrypted',
            'notes' => 'encrypted',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
