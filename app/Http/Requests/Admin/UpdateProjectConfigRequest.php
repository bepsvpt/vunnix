<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller handles authorization
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
        ];
    }
}
