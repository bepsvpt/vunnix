<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Schemas\CodeReviewSchema;
use App\Schemas\FeatureDevSchema;
use App\Schemas\UiAdjustmentSchema;
use Illuminate\Support\Facades\Log;

/**
 * Central service for validating and processing AI executor results.
 *
 * Validates the structured JSON result against the appropriate schema
 * (based on task type), strips extra fields, and transitions the task
 * to its final state (Completed or Failed).
 *
 * Used by both Path A (runner results via webhook) and Path B
 * (server-side dispatch results from conversation actions).
 *
 * @see §3.5 Result Processor
 */
class ResultProcessor
{
    /**
     * Task types that require structured schema validation.
     *
     * Other types (IssueDiscussion, PrdCreation) produce free-form
     * text and are passed through without schema validation.
     */
    private const SCHEMA_MAP = [
        'code_review' => CodeReviewSchema::class,
        'security_audit' => CodeReviewSchema::class,
        'feature_dev' => FeatureDevSchema::class,
        'ui_adjustment' => UiAdjustmentSchema::class,
    ];

    /**
     * Process a completed task result.
     *
     * Validates the result against the task type's schema, strips extra
     * fields, and transitions the task to Completed or Failed.
     *
     * @return array{success: bool, data: ?array<string, mixed>, errors: array<string, string[]>}
     */
    public function process(Task $task): array
    {
        $result = $task->result;

        // Tasks with no result data cannot be processed
        if ($result === null) {
            return $this->fail($task, 'Result payload is null');
        }

        $schemaClass = self::SCHEMA_MAP[$task->type->value] ?? null;

        // Task types without a schema (IssueDiscussion, PrdCreation) pass through
        if ($schemaClass === null) {
            return $this->succeed($task, $result);
        }

        // Validate against the appropriate schema
        /** @var array{valid: bool, errors: array<string, string[]>, data: ?array<string, mixed>} $validation */
        $validation = $schemaClass::validateAndStrip($result);

        if (! $validation['valid']) {
            $errorSummary = $this->formatValidationErrors($validation['errors']);

            return $this->fail($task, "Schema validation failed: {$errorSummary}");
        }

        // Store the sanitized (stripped) result back on the task
        $sanitizedData = $validation['data'] ?? [];
        $task->result = $sanitizedData;
        $task->save();

        return $this->succeed($task, $sanitizedData);
    }

    /**
     * Resolve the schema class for a given task type.
     *
     * @return class-string|null Null if the task type has no schema.
     */
    public function schemaFor(TaskType $type): ?string
    {
        return self::SCHEMA_MAP[$type->value] ?? null;
    }

    /**
     * Transition the task to Completed and return success.
     *
     * @param  array<string, mixed>  $data
     * @return array{success: bool, data: ?array<string, mixed>, errors: array<string, string[]>}
     */
    private function succeed(Task $task, array $data): array
    {
        try {
            $task->transitionTo(TaskStatus::Completed);
        } catch (\App\Exceptions\InvalidTaskTransitionException $e) {
            Log::info('ResultProcessor: task already transitioned, skipping', [
                'task_id' => $task->id,
                'current_status' => $e->from->value,
                'attempted' => $e->to->value,
            ]);

            return [
                'success' => false,
                'data' => null,
                'errors' => ['transition' => ["Task already in {$e->from->value} state"]],
            ];
        }

        Log::info('ResultProcessor: task completed', [
            'task_id' => $task->id,
            'type' => $task->type->value,
        ]);

        return [
            'success' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    /**
     * Transition the task to Failed and return failure.
     *
     * @return array{success: bool, data: ?array<string, mixed>, errors: array<string, string[]>}
     */
    private function fail(Task $task, string $reason): array
    {
        Log::warning('ResultProcessor: validation failed', [
            'task_id' => $task->id,
            'type' => $task->type->value,
            'reason' => $reason,
        ]);

        try {
            $task->transitionTo(TaskStatus::Failed, $reason);
        } catch (\App\Exceptions\InvalidTaskTransitionException $e) {
            Log::info('ResultProcessor: task already transitioned, cannot fail', [
                'task_id' => $task->id,
                'current_status' => $e->from->value,
            ]);
        }

        return [
            'success' => false,
            'data' => null,
            'errors' => ['validation' => [$reason]],
        ];
    }

    /**
     * Format validation errors into a single summary string for logging.
     *
     * @param  array<string, mixed>  $errors
     */
    private function formatValidationErrors(array $errors): string
    {
        $messages = [];

        foreach ($errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = "{$field}: {$error}";
            }
        }

        return implode('; ', array_slice($messages, 0, 5));
    }
}
