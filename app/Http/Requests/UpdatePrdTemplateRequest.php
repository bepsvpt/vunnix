<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrdTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template' => ['present', 'nullable', 'string', 'max:65535'],
        ];
    }
}
