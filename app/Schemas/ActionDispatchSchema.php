<?php

namespace App\Schemas;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates and sanitizes structured output for action dispatch from the Conversation Engine.
 *
 * Schema version: 1.0 (§14.4)
 *
 * This schema is used for preview card generation (T68) — the structured data
 * the AI produces before dispatching an action via the DispatchAction tool.
 *
 * Expected structure:
 *  - action_type: "create_issue" | "implement_feature" | "ui_adjustment" | "create_mr" | "deep_analysis"
 *  - project_id: integer (GitLab project ID)
 *  - title: string (action title, max 500 chars)
 *  - description: string (detailed description)
 *  - branch_name: nullable string (for feature/UI/MR actions)
 *  - target_branch: nullable string (base branch, defaults handled by caller)
 *  - assignee_id: nullable integer (GitLab user ID, for create_issue)
 *  - labels: string[] (can be empty)
 */
class ActionDispatchSchema
{
    public const VERSION = '1.0';

    public const ACTION_TYPES = [
        'create_issue',
        'implement_feature',
        'ui_adjustment',
        'create_mr',
        'deep_analysis',
    ];

    /**
     * Validate an action dispatch data array against the schema.
     *
     * @return array{valid: bool, errors: array<string, string[]>}
     */
    public static function validate(array $data): array
    {
        $validator = Validator::make($data, self::rules());

        return [
            'valid' => $validator->passes(),
            'errors' => $validator->errors()->toArray(),
        ];
    }

    /**
     * Strip unknown fields from action dispatch data, keeping only schema-defined keys.
     */
    public static function strip(array $data): array
    {
        $topLevelKeys = [
            'action_type', 'project_id', 'title', 'description',
            'branch_name', 'target_branch', 'assignee_id', 'labels',
        ];

        return array_intersect_key($data, array_flip($topLevelKeys));
    }

    /**
     * Validate and strip in one call. Returns stripped data only if valid.
     *
     * @return array{valid: bool, errors: array<string, string[]>, data: ?array}
     */
    public static function validateAndStrip(array $data): array
    {
        $validation = self::validate($data);

        if (! $validation['valid']) {
            return [
                'valid' => false,
                'errors' => $validation['errors'],
                'data' => null,
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
            'data' => self::strip($data),
        ];
    }

    /**
     * Laravel validation rules for the action dispatch schema.
     */
    public static function rules(): array
    {
        return [
            'action_type' => ['required', 'string', Rule::in(self::ACTION_TYPES)],
            'project_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string'],

            // Optional fields — only relevant for certain action types
            'branch_name' => ['nullable', 'string', 'max:255'],
            'target_branch' => ['nullable', 'string', 'max:255'],
            'assignee_id' => ['nullable', 'integer', 'min:1'],

            // Labels array — can be empty
            'labels' => ['present', 'array'],
            'labels.*' => ['string'],
        ];
    }
}
