<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\Frequency;
use Illuminate\Database\Eloquent\Model;

class CatalogService extends Model
{
    protected $table = 'service_catalog';

    protected $fillable = ['name', 'description', 'price', 'currency', 'frequency', 'is_active'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'currency' => Currency::class,
            'frequency' => Frequency::class,
            'is_active' => 'boolean',
        ];
    }
}
