<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\Frequency;
use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number', 'client_id', 'prospect_name', 'prospect_email', 'prospect_phone', 'prospect_tax_id',
        'currency', 'discount_percent', 'subtotal', 'discount', 'total', 'status', 'valid_until', 'notes',
        'sent_at', 'approved_at', 'rejected_at', 'converted_client_id', 'created_by',
        'custom_pdf_path', 'custom_pdf_name',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'status' => QuoteStatus::class,
            'discount_percent' => 'decimal:2',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ¿Tiene un PDF propio (Canva) que reemplaza al generado? */
    public function hasCustomPdf(): bool
    {
        return filled($this->custom_pdf_path);
    }

    /** Nombre de quien recibe el presupuesto (cliente existente o prospecto). */
    public function recipientName(): string
    {
        return $this->client?->displayName() ?? $this->prospect_name ?? 'Sin destinatario';
    }

    public function recipientEmail(): ?string
    {
        return $this->client?->email ?: $this->prospect_email;
    }

    public function recipientPhone(): ?string
    {
        return $this->client?->whatsapp ?: ($this->client?->phone ?: $this->prospect_phone);
    }

    public function isExpired(): bool
    {
        return in_array($this->status, [QuoteStatus::Borrador, QuoteStatus::Enviado], true)
            && $this->valid_until !== null
            && $this->valid_until->endOfDay()->isPast();
    }

    /** Estado visible: un borrador/enviado cuya validez pasó se muestra como vencido. */
    public function effectiveStatus(): QuoteStatus
    {
        return $this->isExpired() ? QuoteStatus::Vencido : $this->status;
    }

    /** Solo se edita mientras no se resolvió (aprobado/rechazado). */
    public function isEditable(): bool
    {
        return in_array($this->status, [QuoteStatus::Borrador, QuoteStatus::Enviado], true);
    }

    public function isConvertible(): bool
    {
        return $this->isEditable() && ! $this->isExpired() && $this->items()->exists();
    }

    /** Recalcula subtotal, descuento y total a partir de los ítems (todo en centavos). */
    public function recalculate(): void
    {
        $subtotal = (int) $this->items()->get()->sum(fn (QuoteItem $i) => $i->lineTotal());
        $discount = (int) round($subtotal * ((float) $this->discount_percent) / 100);

        $this->forceFill(['subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount])->save();
    }

    /** Subtotal de los ítems de un tipo de frecuencia (para mostrar "mensual" y "único" por separado). */
    public function subtotalFor(Frequency $frequency): int
    {
        return (int) $this->items->where('frequency', $frequency)->sum(fn (QuoteItem $i) => $i->lineTotal());
    }
}
