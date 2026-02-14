<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the executor's result payload per §20.4 Runner Result API.
 *
 * Fields:
 *  - status: "completed" | "failed" (required)
 *  - result: structured JSON (required when completed)
 *  - error: error message string (optional, populated when failed)
 *  - tokens: { input, output, thinking } (required)
 *  - duration_seconds: integer (required)
 *  - prompt_version: { skill, claude_md, schema } (required)
 */
class StoreTaskResultRequest extends FormRequest
{
    /**
     * Task token middleware handles authorization — always allow here.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['completed', 'failed'])],

            'result' => ['required_if:status,completed', 'nullable', 'array'],

            'error' => ['nullable', 'string', 'max:10000'],

            'tokens' => ['required', 'array'],
            'tokens.input' => ['required', 'integer', 'min:0'],
            'tokens.output' => ['required', 'integer', 'min:0'],
            'tokens.thinking' => ['required', 'integer', 'min:0'],

            'duration_seconds' => ['required', 'integer', 'min:0'],

            'prompt_version' => ['required', 'array'],
            'prompt_version.skill' => ['required', 'string', 'max:255'],
            'prompt_version.claude_md' => ['required', 'string', 'max:255'],
            'prompt_version.schema' => ['required', 'string', 'max:255'],
        ];
    }
}
