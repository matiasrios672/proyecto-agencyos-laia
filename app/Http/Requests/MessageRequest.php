<?php

namespace App\Http\Requests;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;

class MessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El acceso a la conversación / autoría se resuelve en el controlador.
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.Message::MAX_LENGTH],
            'mentions' => ['nullable', 'array', 'max:20'],
            'mentions.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Escribí un mensaje.',
            'body.max' => 'El mensaje es demasiado largo (máximo '.Message::MAX_LENGTH.' caracteres).',
        ];
    }
}
