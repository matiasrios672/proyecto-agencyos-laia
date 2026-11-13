<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContentGrid extends Model
{
    protected $fillable = [
        'client_id', 'month', 'title', 'file_path', 'original_name', 'size', 'status', 'notes',
        'uploaded_by', 'approved_at', 'shared_at',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'size' => 'integer',
            'status' => ContentStatus::class,
            'approved_at' => 'datetime',
            'shared_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** "Noviembre 2026" */
    public function monthLabel(): string
    {
        return ucfirst($this->month->copy()->locale('es')->translatedFormat('F Y'));
    }

    /** Nombre seguro para descargar (no sale del archivo subido, así que no puede inyectar nada en los headers). */
    public function downloadName(): string
    {
        return 'Grilla-'.Str::slug($this->client?->displayName() ?? 'cliente').'-'.$this->month->format('Y-m').'.pdf';
    }
}
