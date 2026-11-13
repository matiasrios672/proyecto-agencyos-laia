<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KanbanBoard extends Model
{
    /** Columnas iniciales de todo tablero de cliente. */
    public const DEFAULT_COLUMNS = ['pendientes' => 'Pendientes', 'listo' => 'Listo', 'publicado' => 'Publicado'];

    protected $fillable = ['client_id', 'name'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(KanbanColumn::class, 'board_id')->orderBy('position');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(KanbanCard::class, 'board_id');
    }
}
