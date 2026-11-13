<?php

namespace App\Models;

use App\Enums\Frequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['quote_id', 'service_id', 'description', 'quantity', 'unit_price', 'frequency', 'position'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'frequency' => Frequency::class,
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'service_id');
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_price;
    }
}
