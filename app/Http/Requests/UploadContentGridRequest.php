<?php

namespace App\Http\Requests;

use App\Rules\RealPdf;
use App\Services\PdfStorage;
use Illuminate\Foundation\Http\FormRequest;

class UploadContentGridRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ContentGridPolicy@manageForClient en el controlador.
    }

    public function rules(): array
    {
        return [
            // Mes en formato AAAA-MM, desde 2020 hasta dos años hacia adelante.
            'month' => ['required', 'regex:/^20\d{2}-(0[1-9]|1[0-2])$/', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value > now()->addYears(2)->format('Y-m')) {
                    $fail('El mes elegido está demasiado lejos.');
                }
            }],
            'title' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'replace' => ['sometimes', 'boolean'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:'.PdfStorage::MAX_KB, new RealPdf],
        ];
    }

    public function attributes(): array
    {
        return ['month' => 'mes', 'title' => 'título', 'notes' => 'notas', 'file' => 'archivo'];
    }

    public function messages(): array
    {
        return [
            'month.regex' => 'Elegí un mes válido (por ejemplo 2026-11).',
            'file.required' => 'Elegí el PDF de la grilla.',
            'file.mimes' => 'El archivo tiene que ser un PDF.',
            'file.max' => 'El PDF no puede pesar más de 25 MB.',
            'file.uploaded' => 'No se pudo subir el archivo. Revisá que no pese más de 25 MB e intentá de nuevo.',
        ];
    }
}
