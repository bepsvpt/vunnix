<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\MemoryExtractionService;
use App\Services\ProjectMemoryService;
use Illuminate\Console\Command;

class AnalyzeCrossMRPatterns extends Command
{
    protected $signature = 'memory:analyze-patterns';

    protected $description = 'Analyze finding acceptance history for cross-MR memory patterns';

    public function handle(
        MemoryExtractionService $extractionService,
        ProjectMemoryService $projectMemoryService,
    ): int {
        if (! (bool) config('vunnix.memory.enabled', true) || ! (bool) config('vunnix.memory.cross_mr_patterns', true)) {
            $this->info('Cross-MR pattern analysis disabled via feature flag.');

            return self::SUCCESS;
        }

        $createdCount = 0;
        $projects = Project::query()->enabled()->get();

        foreach ($projects as $project) {
            $entries = $extractionService->detectCrossMRPatterns($project);
            foreach ($entries as $entry) {
                $project->memoryEntries()->create([
                    'type' => $entry->type,
                    'category' => $entry->category,
                    'content' => $entry->content,
                    'confidence' => $entry->confidence,
                    'source_task_id' => $entry->source_task_id,
                    'source_meta' => $entry->source_meta,
                    'applied_count' => $entry->applied_count,
                    'archived_at' => $entry->archived_at,
                ]);
                $createdCount++;
            }

            $projectMemoryService->invalidateProjectCache($project->id);
        }

        $this->info("Created {$createdCount} cross-MR memory entr".($createdCount === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
