<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:mcq,multi_select,true_false,short_answer,numerical'],
            'stem' => ['required', 'string'],
            'marks' => ['nullable', 'numeric', 'min:0'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
