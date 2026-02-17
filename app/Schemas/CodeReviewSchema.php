<?php

namespace App\Schemas;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates and sanitizes structured output from the code review executor.
 *
 * Schema version: 1.0 (§14.4)
 *
 * Expected structure:
 *  - version: string
 *  - summary: { risk_level, total_findings, findings_by_severity, walkthrough[] }
 *  - findings[]: { id, severity, category, file, line, end_line, title, description, suggestion, labels[] }
 *  - labels[]: string
 *  - commit_status: "success" | "failed"
 */
class CodeReviewSchema
{
    public const VERSION = '1.0';

    public const SEVERITIES = ['critical', 'major', 'minor'];

    public const CATEGORIES = ['security', 'bug', 'performance', 'style', 'convention', 'prompt-injection'];

    public const RISK_LEVELS = ['high', 'medium', 'low'];

    public const COMMIT_STATUSES = ['success', 'failed'];

    /**
     * Validate a code review result array against the schema.
     *
     * @param  array<string, mixed>  $data
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
     * Strip unknown fields from a code review result, keeping only schema-defined keys.
     *
     * Operates recursively on nested objects (summary, findings, walkthrough).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function strip(array $data): array
    {
        $topLevelKeys = ['version', 'summary', 'findings', 'labels', 'commit_status'];
        $result = array_intersect_key($data, array_flip($topLevelKeys));

        // Strip summary sub-fields
        if (isset($result['summary']) && is_array($result['summary'])) {
            $summaryKeys = ['risk_level', 'total_findings', 'findings_by_severity', 'walkthrough'];
            $result['summary'] = array_intersect_key($result['summary'], array_flip($summaryKeys));

            // Strip findings_by_severity sub-fields
            if (isset($result['summary']['findings_by_severity']) && is_array($result['summary']['findings_by_severity'])) {
                $severityKeys = ['critical', 'major', 'minor'];
                $result['summary']['findings_by_severity'] = array_intersect_key(
                    $result['summary']['findings_by_severity'],
                    array_flip($severityKeys),
                );
            }

            // Strip walkthrough entries
            if (isset($result['summary']['walkthrough']) && is_array($result['summary']['walkthrough'])) {
                $walkthroughKeys = ['file', 'change_summary'];
                $result['summary']['walkthrough'] = array_map(
                    fn (array $entry): array => array_intersect_key($entry, array_flip($walkthroughKeys)),
                    $result['summary']['walkthrough'],
                );
            }
        }

        // Strip findings sub-fields
        if (isset($result['findings']) && is_array($result['findings'])) {
            $findingKeys = [
                'id', 'severity', 'category', 'file', 'line',
                'end_line', 'title', 'description', 'suggestion', 'labels',
            ];
            $result['findings'] = array_map(
                fn (array $finding): array => array_intersect_key($finding, array_flip($findingKeys)),
                $result['findings'],
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
     * Laravel validation rules for the code review schema.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'version' => ['required', 'string'],

            // Summary
            'summary' => ['required', 'array'],
            'summary.risk_level' => ['required', 'string', Rule::in(self::RISK_LEVELS)],
            'summary.total_findings' => ['required', 'integer', 'min:0'],
            'summary.findings_by_severity' => ['required', 'array'],
            'summary.findings_by_severity.critical' => ['required', 'integer', 'min:0'],
            'summary.findings_by_severity.major' => ['required', 'integer', 'min:0'],
            'summary.findings_by_severity.minor' => ['required', 'integer', 'min:0'],
            'summary.walkthrough' => ['required', 'array'],
            'summary.walkthrough.*.file' => ['required', 'string'],
            'summary.walkthrough.*.change_summary' => ['required', 'string'],

            // Findings
            'findings' => ['present', 'array'],
            'findings.*.id' => ['required', 'integer', 'min:1'],
            'findings.*.severity' => ['required', 'string', Rule::in(self::SEVERITIES)],
            'findings.*.category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'findings.*.file' => ['required', 'string'],
            'findings.*.line' => ['required', 'integer', 'min:1'],
            'findings.*.end_line' => ['required', 'integer', 'min:1'],
            'findings.*.title' => ['required', 'string', 'max:500'],
            'findings.*.description' => ['required', 'string'],
            'findings.*.suggestion' => ['required', 'string'],
            'findings.*.labels' => ['present', 'array'],
            'findings.*.labels.*' => ['string'],

            // Labels & commit status
            'labels' => ['required', 'array'],
            'labels.*' => ['string'],
            'commit_status' => ['required', 'string', Rule::in(self::COMMIT_STATUSES)],
        ];
    }
}
