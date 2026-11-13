<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\TaxCondition;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_name', 'trade_name', 'tax_id', 'tax_condition', 'contact_name', 'email', 'phone',
        'whatsapp', 'website', 'address', 'city', 'notes', 'account_status', 'laiatime_instance_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_status' => AccountStatus::class,
            'tax_condition' => TaxCondition::class,
            'is_active' => 'boolean',
            'laiatime_synced_at' => 'datetime',
            'laiatime_suspended_at' => 'datetime',
            'laiatime_suspended_by_system' => 'boolean',
            'laiatime_override_until' => 'date',
        ];
    }

    public function hasLaiaTime(): bool
    {
        return filled($this->laiatime_instance_id);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function vaultItems(): HasMany
    {
        return $this->hasMany(VaultItem::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(ClientService::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function contentGrids(): HasMany
    {
        return $this->hasMany(ContentGrid::class);
    }

    public function board(): HasOne
    {
        return $this->hasOne(KanbanBoard::class);
    }

    /** Crea el tablero inicial (Pendientes / Listo / Publicado) si el cliente todavía no tiene uno. */
    public function ensureBoard(): KanbanBoard
    {
        $board = $this->board()->firstOrCreate([], ['name' => 'Tablero de '.$this->displayName()]);

        $position = 0;
        foreach (KanbanBoard::DEFAULT_COLUMNS as $key => $name) {
            $board->columns()->firstOrCreate(['key' => $key], ['name' => $name, 'position' => $position++]);
        }

        return $board;
    }

    /** Un empleado solo ve sus clientes asignados; quien tenga clients.view_all (o sea sysadmin) ve todos. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('clients.view_all')) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $q) => $q->whereKey($user->id));
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->business_name;
    }
}
