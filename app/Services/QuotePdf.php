<?php

namespace App\Services;

use App\Enums\Frequency;
use App\Models\Quote;
use App\Support\Money;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;

/** Genera el PDF de un presupuesto con la identidad de Laia Estudio Creativo Digital. */
class QuotePdf
{
    /**
     * PDF del presupuesto. Si tiene un PDF propio (armado en Canva) se entrega ESE archivo: la descarga, el enlace
     * público firmado y el adjunto del email pasan todos por acá, así que usan siempre el mismo documento.
     */
    public static function render(Quote $quote): string
    {
        $pdfs = app(PdfStorage::class);
        if ($quote->hasCustomPdf() && $pdfs->exists($quote->custom_pdf_path)) {
            return $pdfs->contents($quote->custom_pdf_path);
        }

        $quote->loadMissing('items', 'client', 'creator');
        $currency = $quote->currency;

        $items = $quote->items->map(fn ($i) => [
            'description' => $i->description,
            'quantity' => $i->quantity,
            'frequency' => $i->frequency->label(),
            'unit_price' => Money::format($i->unit_price, $currency),
            'total' => Money::format($i->lineTotal(), $currency),
        ]);

        return Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'logo' => self::logo(),
            'items' => $items,
            'recipient' => [
                'name' => $quote->recipientName(),
                'tax_id' => $quote->client?->tax_id ?? $quote->prospect_tax_id,
                'email' => $quote->recipientEmail(),
                'phone' => $quote->recipientPhone(),
            ],
            'money' => [
                'monthly' => Money::format($quote->subtotalFor(Frequency::Mensual), $currency),
                'once' => Money::format($quote->subtotalFor(Frequency::Unico), $currency),
                'subtotal' => Money::format($quote->subtotal, $currency),
                'discount' => Money::format($quote->discount, $currency),
                'total' => Money::format($quote->total, $currency),
            ],
            'bank' => collect(['holder', 'cuit', 'name', 'cbu', 'alias'])
                ->mapWithKeys(fn ($k) => [$k => Settings::get("bank.{$k}")])->filter()->all(),
        ])->setPaper('a4')->output();
    }

    /** Logo oficial (versión blanca) embebido como data URI: el PDF no depende de ningún archivo externo. */
    private static function logo(): ?string
    {
        $path = public_path('brand/logo-white.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }
}
