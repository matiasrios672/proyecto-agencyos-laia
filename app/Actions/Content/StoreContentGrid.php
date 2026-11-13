<?php

namespace App\Actions\Content;

use App\Enums\ContentStatus;
use App\Models\Client;
use App\Models\ContentGrid;
use App\Models\User;
use App\Services\PdfStorage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sube la grilla de un mes. Si ya había una de ese mes, solo la reemplaza cuando el usuario lo confirmó
 * (`replace`): el PDF viejo se borra y la grilla vuelve a borrador, porque una aprobación no vale para otro archivo.
 */
class StoreContentGrid
{
    public function __construct(private readonly PdfStorage $pdfs) {}

    /** @param  array{month: string, title?: ?string, notes?: ?string}  $data */
    public function handle(Client $client, User $actor, array $data, UploadedFile $file, bool $replace = false): ContentGrid
    {
        $month = Carbon::createFromFormat('!Y-m-d', $data['month'].'-01');
        $label = ucfirst($month->copy()->locale('es')->translatedFormat('F Y'));
        $newPath = null;
        $oldPath = null;

        try {
            $grid = DB::transaction(function () use ($client, $actor, $data, $file, $replace, $month, $label, &$newPath, &$oldPath) {
                $existing = ContentGrid::where('client_id', $client->id)->whereDate('month', $month)->lockForUpdate()->first();

                if ($existing && ! $replace) {
                    throw $this->alreadyExists($label);
                }

                $newPath = $this->pdfs->store($file, "content-grids/{$client->id}");
                $attributes = [
                    'title' => filled($data['title'] ?? null) ? $data['title'] : "Grilla de contenido — {$label}",
                    'file_path' => $newPath,
                    'original_name' => $this->displayName($file),
                    'size' => $file->getSize(),
                    'notes' => $data['notes'] ?? null,
                    'uploaded_by' => $actor->id,
                ];

                if ($existing) {
                    $oldPath = $existing->file_path;
                    $existing->forceFill($attributes + ['status' => ContentStatus::Borrador, 'approved_at' => null, 'shared_at' => null])->save();
                    $this->log($existing, $actor, 'Grilla de contenido reemplazada');

                    return $existing;
                }

                $grid = ContentGrid::create($attributes + ['client_id' => $client->id, 'month' => $month, 'status' => ContentStatus::Borrador]);
                $this->log($grid, $actor, 'Grilla de contenido subida');

                return $grid;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos subidas simultáneas del mismo mes: la segunda se entera de que ya existe.
            $this->pdfs->delete($newPath);
            throw $this->alreadyExists($label);
        } catch (Throwable $e) {
            $this->pdfs->delete($newPath);
            throw $e;
        }

        // El archivo viejo se borra recién cuando el reemplazo quedó confirmado en la base.
        $this->pdfs->delete($oldPath);

        return $grid;
    }

    private function alreadyExists(string $label): ValidationException
    {
        return ValidationException::withMessages(['replace' => "Ya hay una grilla de {$label} para este cliente. Confirmá para reemplazarla."]);
    }

    /** Nombre para mostrar: solo el nombre base, sin rutas, acotado. Nunca se usa para guardar. */
    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'grilla.pdf', 0, 180);
    }

    private function log(ContentGrid $grid, User $actor, string $description): void
    {
        activity('content')->performedOn($grid)->causedBy($actor)
            ->withProperties(['client_id' => $grid->client_id, 'month' => $grid->month->format('Y-m'), 'size' => $grid->size])
            ->log($description);
    }
}
