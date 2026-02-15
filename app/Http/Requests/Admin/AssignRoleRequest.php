<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ];
    }
}
