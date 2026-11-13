<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Conversation extends Model
{
    public const DIRECT = 'direct';

    public const THREAD = 'thread';

    /** Sujetos permitidos para los hilos contextuales (lista blanca: nunca una clase arbitraria del request). */
    public const SUBJECTS = ['client' => Client::class];

    protected $fillable = ['type', 'subject_type', 'subject_id', 'direct_key', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['last_read_at', 'last_read_message_id'])->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isDirect(): bool
    {
        return $this->type === self::DIRECT;
    }

    public function isThread(): bool
    {
        return $this->type === self::THREAD;
    }

    /** El cliente del hilo (null si no es un hilo, si el tipo no está permitido o si el cliente fue eliminado). */
    public function subject(): ?Model
    {
        $class = self::SUBJECTS[$this->subject_type] ?? null;

        return $this->isThread() && $class ? $class::find($this->subject_id) : null;
    }

    /**
     * Quién puede leer y escribir:
     *  - chat directo: solo sus dos participantes (ni siquiera el sysadmin: son mensajes privados);
     *  - hilo de cliente: quien tenga acceso a ese cliente (equipo asignado, Dirección y sysadmin).
     */
    public function isAccessibleBy(User $user): bool
    {
        if (! $user->is_active || ! $user->can('messages.use')) {
            return false;
        }

        if ($this->isDirect()) {
            return $this->participants()->whereKey($user->id)->exists();
        }

        $subject = $this->subject();

        return $subject instanceof Client && $user->can('view', $subject);
    }

    /** @return Collection<int, User> personas activas con acceso a la conversación (candidatos a mención y destinatarios). */
    public function eligibleUsers(): Collection
    {
        if ($this->isDirect()) {
            return $this->participants()->where('is_active', true)->get();
        }

        return User::query()->where('is_active', true)->get()->filter(fn (User $u) => $this->isAccessibleBy($u))->values();
    }
}
