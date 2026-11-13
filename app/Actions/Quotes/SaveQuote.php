<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\User;
use App\Services\PdfStorage;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Crea o actualiza un presupuesto con sus ítems y recalcula los totales (en centavos). */
class SaveQuote
{
    public function __construct(private readonly PdfStorage $pdfs) {}

    /**
     * @param  UploadedFile|null  $customPdf  PDF propio (armado en Canva): reemplaza al que genera el sistema.
     * @param  bool  $removeCustomPdf  Quitar el PDF propio y volver al generado (se ignora si llega uno nuevo).
     */
    public function handle(User $actor, array $data, ?Quote $quote = null, ?UploadedFile $customPdf = null, bool $removeCustomPdf = false): Quote
    {
        $newPath = null;
        $obsoletePath = null;

        try {
            $quote = DB::transaction(function () use ($actor, $data, $quote, $customPdf, $removeCustomPdf, &$newPath, &$obsoletePath) {
                $attributes = [
                    'client_id' => $data['client_id'] ?? null,
                    'currency' => $data['currency'],
                    'discount_percent' => $data['discount_percent'] ?? 0,
                    'valid_until' => $data['valid_until'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    // Con cliente existente no se guardan datos de prospecto (evita información duplicada).
                    'prospect_name' => $data['client_id'] ?? null ? null : ($data['prospect_name'] ?? null),
                    'prospect_email' => $data['client_id'] ?? null ? null : ($data['prospect_email'] ?? null),
                    'prospect_phone' => $data['client_id'] ?? null ? null : ($data['prospect_phone'] ?? null),
                    'prospect_tax_id' => $data['client_id'] ?? null ? null : ($data['prospect_tax_id'] ?? null),
                ];

                if ($quote === null) {
                    $quote = Quote::create($attributes + [
                        'number' => $this->nextNumber(),
                        'status' => QuoteStatus::Borrador,
                        'created_by' => $actor->id,
                    ]);
                } else {
                    $quote->update($attributes);
                    $quote->items()->delete();
                }

                foreach (array_values($data['items']) as $position => $item) {
                    $quote->items()->create([
                        'service_id' => $item['service_id'] ?? null,
                        'description' => $item['description'],
                        'quantity' => (int) $item['quantity'],
                        'unit_price' => Money::toCents($item['unit_price']),
                        'frequency' => $item['frequency'],
                        'position' => $position,
                    ]);
                }

                $quote->recalculate();

                if ($customPdf !== null) {
                    $obsoletePath = $quote->custom_pdf_path;
                    $newPath = $this->pdfs->store($customPdf, "quotes/{$quote->id}");
                    $quote->forceFill(['custom_pdf_path' => $newPath, 'custom_pdf_name' => $this->displayName($customPdf)])->save();
                } elseif ($removeCustomPdf && $quote->custom_pdf_path !== null) {
                    $obsoletePath = $quote->custom_pdf_path;
                    $quote->forceFill(['custom_pdf_path' => null, 'custom_pdf_name' => null])->save();
                }

                return $quote->load('items');
            });
        } catch (Throwable $e) {
            // Si algo falló después de guardar el archivo nuevo, no queda un PDF huérfano en el disco.
            $this->pdfs->delete($newPath);
            throw $e;
        }

        // El archivo anterior se borra recién cuando el cambio quedó confirmado en la base.
        $this->pdfs->delete($obsoletePath);

        return $quote;
    }

    /** Nombre para mostrar (solo el nombre base, acotado). Nunca se usa para guardar. */
    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'presupuesto.pdf', 0, 180);
    }

    /** P-AAAA-0001, correlativo por año. El lock evita que dos altas simultáneas tomen el mismo número. */
    private function nextNumber(): string
    {
        $year = now()->year;
        DB::select('select pg_advisory_xact_lock(?)', [7_000_000 + $year]);

        $last = Quote::withTrashed()->where('number', 'like', "P-{$year}-%")->max('number');
        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('P-%d-%04d', $year, $next);
    }
}
