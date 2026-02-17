<?php

namespace App\Models;

use App\Enums\TaskOrigin;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Exceptions\InvalidTaskTransitionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'origin',
        'user_id',
        'project_id',
        'priority',
        'status',
        'mr_iid',
        'issue_iid',
        'comment_id',
        'commit_sha',
        'pipeline_id',
        'pipeline_status',
        'conversation_id',
        'prompt',
        'response',
        'tokens_used',
        'input_tokens',
        'output_tokens',
        'model',
        'result',
        'prompt_version',
        'cost',
        'duration_seconds',
        'retry_count',
        'error_reason',
        'superseded_by_id',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array{
     *   type: 'App\Enums\TaskType',
     *   origin: 'App\Enums\TaskOrigin',
     *   priority: 'App\Enums\TaskPriority',
     *   status: 'App\Enums\TaskStatus',
     *   result: 'array',
     *   prompt_version: 'array',
     *   started_at: 'datetime',
     *   completed_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'origin' => TaskOrigin::class,
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'result' => 'array',
            'prompt_version' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasOne<TaskMetric, $this> */
    public function metric(): HasOne
    {
        return $this->hasOne(TaskMetric::class);
    }

    /** @return HasMany<FindingAcceptance, $this> */
    public function findingAcceptances(): HasMany
    {
        return $this->hasMany(FindingAcceptance::class);
    }

    // ─── State Machine ──────────────────────────────────────────────

    /**
     * Transition this task to a new status.
     *
     * @throws InvalidTaskTransitionException
     */
    public function transitionTo(TaskStatus $newStatus, ?string $errorReason = null): void
    {
        $currentStatus = $this->status;

        if (! $currentStatus->canTransitionTo($newStatus)) {
            throw new InvalidTaskTransitionException($currentStatus, $newStatus);
        }

        $this->status = $newStatus;

        // Set timestamps on specific transitions
        if ($newStatus === TaskStatus::Running) {
            $this->started_at = now();
        }

        if ($newStatus === TaskStatus::Completed) {
            $this->completed_at = now();
        }

        // Set error reason on failure
        if ($newStatus === TaskStatus::Failed && $errorReason !== null) {
            $this->error_reason = $errorReason;
        }

        // Increment retry count when retrying (failed → queued)
        if ($currentStatus === TaskStatus::Failed && $newStatus === TaskStatus::Queued) {
            $this->retry_count = $this->retry_count + 1;
        }

        $this->save();
    }

    /**
     * Check if this task is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Supersede all queued/running tasks for a given MR, marking them
     * as superseded by the given new task ID.
     */
    public static function supersedeForMergeRequest(int $projectId, int $mrIid, int $excludeTaskId): int
    {
        $tasks = static::where('project_id', $projectId)
            ->where('mr_iid', $mrIid)
            ->where('id', '!=', $excludeTaskId)
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])
            ->get();

        $count = 0;
        foreach ($tasks as $task) {
            $task->superseded_by_id = $excludeTaskId;
            $task->transitionTo(TaskStatus::Superseded);
            $count++;
        }

        return $count;
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * Scope to tasks that are currently active (queued or running).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [TaskStatus::Queued, TaskStatus::Running]);
    }

    /**
     * Scope to tasks for a specific merge request in a project.
     */
    public function scopeForMergeRequest(Builder $query, int $projectId, int $mrIid): Builder
    {
        return $query->where('project_id', $projectId)->where('mr_iid', $mrIid);
    }
}
