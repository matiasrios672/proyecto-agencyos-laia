<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\Frequency;
use App\Rules\Cuit;
use App\Rules\RealPdf;
use App\Services\PdfStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // QuotePolicy en el controlador.
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('prospect_tax_id')) {
            $this->merge(['prospect_tax_id' => Cuit::normalize($this->input('prospect_tax_id'))]);
        }
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'prospect_name' => ['nullable', 'required_without:client_id', 'string', 'max:160'],
            'prospect_email' => ['nullable', 'email', 'max:160'],
            'prospect_phone' => ['nullable', 'string', 'max:40'],
            'prospect_tax_id' => ['nullable', new Cuit],
            'currency' => ['required', Rule::enum(Currency::class)],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.service_id' => ['nullable', 'integer', 'exists:service_catalog,id'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'items.*.frequency' => ['required', Rule::enum(Frequency::class)],
            // PDF propio (Canva): opcional, mismas validaciones de contenido que las grillas.
            'custom_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:'.PdfStorage::MAX_KB, new RealPdf],
            'remove_custom_pdf' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'prospect_name' => 'nombre del prospecto',
            'prospect_email' => 'email del prospecto',
            'prospect_tax_id' => 'CUIT del prospecto',
            'valid_until' => 'fecha de validez',
            'items' => 'ítems',
            'items.*.description' => 'descripción',
            'items.*.quantity' => 'cantidad',
            'items.*.unit_price' => 'precio',
            'custom_pdf' => 'PDF propio',
        ];
    }

    public function messages(): array
    {
        return [
            'prospect_name.required_without' => 'Elegí un cliente o ingresá el nombre del prospecto.',
            'items.required' => 'Agregá al menos un servicio al presupuesto.',
            'items.min' => 'Agregá al menos un servicio al presupuesto.',
            'valid_until.after_or_equal' => 'La fecha de validez no puede ser anterior a hoy.',
            'custom_pdf.mimes' => 'El PDF propio tiene que ser un archivo PDF.',
            'custom_pdf.max' => 'El PDF propio no puede pesar más de 25 MB.',
            'custom_pdf.uploaded' => 'No se pudo subir el PDF. Revisá que no pese más de 25 MB e intentá de nuevo.',
        ];
    }
}
