<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\MemoryExtractionService;
use App\Services\ProjectMemoryService;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractReviewPatterns implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(
        MemoryExtractionService $extractionService,
        ProjectMemoryService $projectMemoryService,
    ): void {
        try {
            $task = Task::with(['project', 'findingAcceptances'])->find($this->taskId);
            if ($task === null) {
                return;
            }

            $entries = $extractionService->extractFromFindings($task->project, $task->findingAcceptances);
            foreach ($entries as $entry) {
                $task->project->memoryEntries()->create([
                    'type' => $entry->type,
                    'category' => $entry->category,
                    'content' => $entry->content,
                    'confidence' => $entry->confidence,
                    'source_task_id' => $entry->source_task_id ?? $task->id,
                    'source_meta' => $entry->source_meta,
                    'applied_count' => $entry->applied_count ?? 0,
                    'archived_at' => $entry->archived_at,
                ]);
            }

            $projectMemoryService->invalidateProjectCache($task->project_id);
        } catch (Throwable $e) {
            Log::warning('ExtractReviewPatterns failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
