<?php

namespace App\Http\Requests;

use App\Enums\SplitRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.quote_item_id' => ['required', 'integer'],
            'items.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'items.*.split_rule' => ['nullable', Rule::enum(SplitRule::class)],
            'items.*.split_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.split_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
