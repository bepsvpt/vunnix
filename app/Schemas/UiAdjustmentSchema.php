<?php

namespace App\Schemas;

/**
 * Validates and sanitizes structured output from the UI adjustment executor.
 *
 * Schema version: 1.0 (§14.4)
 *
 * Extends the feature development schema with screenshot fields (D131):
 *  - version: string
 *  - branch: string
 *  - mr_title: string
 *  - mr_description: string
 *  - files_changed[]: { path, action, summary }
 *  - tests_added: boolean
 *  - screenshot: string|null (base64-encoded PNG or null if capture failed)
 *  - screenshot_mobile: string|null (base64-encoded PNG or null)
 *  - notes: string
 */
class UiAdjustmentSchema extends FeatureDevSchema
{
    /**
     * Laravel validation rules for the UI adjustment schema.
     *
     * Inherits all feature dev rules and adds screenshot fields.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return array_merge(parent::rules(), [
            'screenshot' => ['present', 'nullable', 'string'],
            'screenshot_mobile' => ['present', 'nullable', 'string'],
        ]);
    }

    /**
     * Top-level keys allowed in the schema.
     *
     * @return array<int, string>
     */
    protected static function topLevelKeys(): array
    {
        return array_merge(parent::topLevelKeys(), ['screenshot', 'screenshot_mobile']);
    }
}
