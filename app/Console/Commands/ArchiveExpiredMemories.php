<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ProjectMemoryService;
use Illuminate\Console\Command;

class ArchiveExpiredMemories extends Command
{
    protected $signature = 'memory:archive-expired';

    protected $description = 'Archive project memory entries older than retention policy';

    public function handle(ProjectMemoryService $projectMemoryService): int
    {
        $totalArchived = 0;

        foreach (Project::query()->get() as $project) {
            $archived = $projectMemoryService->archiveExpired($project);
            $totalArchived += $archived;

            if ($archived > 0) {
                $this->info("Project {$project->id}: archived {$archived} entr".($archived === 1 ? 'y' : 'ies').'.');
            }
        }

        if ($totalArchived === 0) {
            $this->info('No expired memory entries to archive.');
        } else {
            $this->info("Archived {$totalArchived} total memory entr".($totalArchived === 1 ? 'y' : 'ies').'.');
        }

        return self::SUCCESS;
    }
}
