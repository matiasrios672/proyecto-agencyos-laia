<?php

namespace App\Http\Requests;

use App\Enums\TaxCondition;
use App\Rules\Cuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorización se resuelve con ClientPolicy en el controlador.
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('tax_id')) {
            $this->merge(['tax_id' => Cuit::normalize($this->input('tax_id'))]);
        }
    }

    public function rules(): array
    {
        $client = $this->route('client');

        return [
            'business_name' => ['required', 'string', 'max:160'],
            'trade_name' => ['nullable', 'string', 'max:160'],
            'tax_id' => ['nullable', new Cuit, Rule::unique('clients', 'tax_id')->ignore($client?->id)->withoutTrashed()],
            'tax_condition' => ['nullable', Rule::enum(TaxCondition::class)],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // account_status no se edita: lo deriva el sistema de los cobros (App\Actions\Finance\SyncAccountStatus).
            'laiatime_instance_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'business_name' => 'razón social',
            'tax_id' => 'CUIT',
            'email' => 'email',
            'tax_condition' => 'condición frente al IVA',
        ];
    }
}
