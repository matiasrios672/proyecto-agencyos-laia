<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda y entrega PDF subidos por usuarios en el disco PRIVADO (storage/app/private), nunca en `public`.
 * El archivo se guarda con un nombre aleatorio: el nombre original no toca el disco.
 */
class PdfStorage
{
    public const DISK = 'local';
    public const MAX_KB = 25600; // 25 MB

    public function store(UploadedFile $file, string $directory): string
    {
        return (string) $file->storeAs($directory, Str::random(40).'.pdf', self::DISK);
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    public function contents(string $path): string
    {
        return (string) Storage::disk(self::DISK)->get($path);
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Respuesta con el PDF: inline (visor) o adjunto, con los headers que evitan que se interprete como otra cosa. */
    public function response(string $path, string $downloadName, bool $download = false)
    {
        return Storage::disk(self::DISK)->response($path, $downloadName, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $download ? 'attachment' : 'inline');
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1_048_576 ? number_format($bytes / 1_048_576, 1, ',', '.').' MB' : number_format(max(1, $bytes / 1024), 0, ',', '.').' KB';
    }
}
