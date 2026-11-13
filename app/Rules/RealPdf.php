<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Verifica que el archivo sea realmente un PDF mirando su contenido, no su nombre ni lo que declara el navegador:
 * empieza con los bytes mágicos `%PDF-` y el tipo detectado (finfo) es application/pdf.
 * Así un .exe, una imagen o un texto renombrado a .pdf no pasan.
 */
class RealPdf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || $value->getRealPath() === false) {
            $fail('No se pudo leer el archivo. Probá subirlo de nuevo.');

            return;
        }

        $handle = fopen($value->getRealPath(), 'rb');
        $head = $handle ? (string) fread($handle, 1024) : '';
        if ($handle) {
            fclose($handle);
        }

        // Algunos generadores anteponen unos pocos bytes (BOM / saltos) antes de %PDF-: la especificación lo tolera en 1024.
        $magic = str_contains($head, '%PDF-');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($value->getRealPath());

        if (! $magic || $mime !== 'application/pdf') {
            $fail('El archivo no es un PDF válido.');
        }
    }
}
