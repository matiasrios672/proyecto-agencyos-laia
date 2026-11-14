<?php

namespace App\Http\Requests;

use App\Enums\BillingTiming;
use App\Enums\Currency;
use App\Enums\Frequency;
use App\Enums\SplitRule;
use App\Models\ClientService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ClientPolicy::manageServices en el controlador.
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'frequency' => ['required', Rule::enum(Frequency::class)],
            'due_day' => ['required', 'integer', 'between:1,28'],
            'billing_timing' => ['nullable', Rule::enum(BillingTiming::class)],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'split_rule' => ['required', Rule::enum(SplitRule::class)],
            'split_percent' => ['nullable', 'required_if:split_rule,porcentaje', 'numeric', 'between:0,100'],
            'split_amount' => ['nullable', 'required_if:split_rule,monto_fijo', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(array_keys(ClientService::STATUSES))],
            'save_to_catalog' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'amount' => 'monto', 'due_day' => 'día de vencimiento', 'billing_timing' => 'tipo de facturación', 'split_percent' => 'porcentaje', 'split_amount' => 'monto fijo'];
    }
}
