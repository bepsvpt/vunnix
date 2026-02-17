<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddProjectToConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by controller policy
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ];
    }
}
