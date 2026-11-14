<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Personalización (asunto, cuerpo, activación) de una plantilla definida en config/mail_templates.php,
 * o plantilla propia creada por el Superadministrador (`is_custom`, clave `custom.*`, con nombre y descripción).
 */
class MailTemplate extends Model
{
    protected $fillable = ['key', 'enabled', 'subject', 'body', 'label', 'description', 'is_custom', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'is_custom' => 'boolean'];
    }
}
