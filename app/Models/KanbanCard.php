<?php

namespace App\Models;

use App\Enums\KanbanPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanCard extends Model
{
    protected $fillable = [
        'board_id', 'column_id', 'title', 'description', 'assigned_user_id', 'due_date', 'priority',
        'position', 'created_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => KanbanPriority::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(KanbanBoard::class, 'board_id');
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(KanbanColumn::class, 'column_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Vencida: tiene fecha límite pasada y todavía no se completó (no está en "Publicado"). */
    public function isOverdue(): bool
    {
        return $this->due_date !== null && $this->completed_at === null && $this->due_date->lt(today());
    }
}
