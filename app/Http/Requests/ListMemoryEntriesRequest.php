<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListMemoryEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller handles authorization
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:review_pattern,conversation_fact,cross_mr_pattern,health_signal'],
            'category' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
