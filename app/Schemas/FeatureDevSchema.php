<?php

namespace App\Schemas;

use Illuminate\Support\Facades\Validator;

/**
 * Validates and sanitizes structured output from the feature development executor.
 *
 * Schema version: 1.0 (§14.4)
 *
 * Expected structure:
 *  - version: string
 *  - branch: string
 *  - mr_title: string
 *  - mr_description: string
 *  - files_changed[]: { path, action, summary }
 *  - tests_added: boolean
 *  - notes: string
 */
class FeatureDevSchema
{
    public const VERSION = '1.0';

    public const FILE_ACTIONS = ['created', 'modified'];

    /**
     * Validate a feature development result array against the schema.
     *
     * @param  array<string, mixed>  $data
     * @return array{valid: bool, errors: array<string, string[]>}
     */
    public static function validate(array $data): array
    {
        $validator = Validator::make($data, static::rules());

        return [
            'valid' => $validator->passes(),
            'errors' => $validator->errors()->toArray(),
        ];
    }

    /**
     * Strip unknown fields from a feature development result, keeping only schema-defined keys.
     *
     * Operates recursively on nested objects (files_changed entries).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function strip(array $data): array
    {
        $result = array_intersect_key($data, array_flip(static::topLevelKeys()));

        // Strip files_changed sub-fields
        if (isset($result['files_changed']) && is_array($result['files_changed'])) {
            $fileKeys = ['path', 'action', 'summary'];
            $result['files_changed'] = array_map(
                fn (array $entry): array => array_intersect_key($entry, array_flip($fileKeys)),
                $result['files_changed'],
            );
        }

        return $result;
    }

    /**
     * Validate and strip in one call. Returns stripped data only if valid.
     *
     * @param  array<string, mixed>  $data
     * @return array{valid: bool, errors: array<string, string[]>, data: ?array<string, mixed>}
     */
    public static function validateAndStrip(array $data): array
    {
        $validation = static::validate($data);

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
            'data' => static::strip($data),
        ];
    }

    /**
     * Laravel validation rules for the feature development schema.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'version' => ['required', 'string'],

            // Branch & MR info
            'branch' => ['required', 'string', 'max:255'],
            'mr_title' => ['required', 'string', 'max:500'],
            'mr_description' => ['required', 'string'],

            // Files changed
            'files_changed' => ['required', 'array'],
            'files_changed.*.path' => ['required', 'string'],
            'files_changed.*.action' => ['required', 'string', 'in:created,modified'],
            'files_changed.*.summary' => ['required', 'string'],

            // Tests & notes
            'tests_added' => ['required', 'boolean'],
            'notes' => ['required', 'string'],
        ];
    }

    /**
     * Top-level keys allowed in the schema.
     *
     * @return array<int, string>
     */
    protected static function topLevelKeys(): array
    {
        return ['version', 'branch', 'mr_title', 'mr_description', 'files_changed', 'tests_added', 'notes'];
    }
}
