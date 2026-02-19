<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\MemoryExtractionService;
use App\Services\ProjectMemoryService;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractConversationFacts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $conversationMeta
     */
    public function __construct(
        public readonly string $summary,
        public readonly int $projectId,
        public readonly array $conversationMeta = [],
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(
        MemoryExtractionService $extractionService,
        ProjectMemoryService $projectMemoryService,
    ): void {
        try {
            $project = Project::find($this->projectId);
            if ($project === null) {
                return;
            }

            $entries = $extractionService->extractFromConversationSummary($project, $this->summary, $this->conversationMeta);
            foreach ($entries as $entry) {
                $project->memoryEntries()->create([
                    'type' => $entry->type,
                    'category' => $entry->category,
                    'content' => $entry->content,
                    'confidence' => $entry->confidence,
                    'source_task_id' => $entry->source_task_id,
                    'source_meta' => $entry->source_meta,
                    'applied_count' => $entry->applied_count ?? 0,
                    'archived_at' => $entry->archived_at,
                ]);
            }

            $projectMemoryService->invalidateProjectCache($project->id);
        } catch (Throwable $e) {
            Log::warning('ExtractConversationFacts failed', [
                'project_id' => $this->projectId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
