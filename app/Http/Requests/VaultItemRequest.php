<?php

namespace App\Http\Requests;

use App\Enums\VaultCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VaultItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(VaultCategory::class)],
            'label' => ['required', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:160'],
            // En edición, vacío = conservar el valor actual.
            'secret' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
