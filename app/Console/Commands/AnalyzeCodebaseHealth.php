<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeProjectHealth;
use App\Models\Project;
use App\Services\ProjectConfigService;
use Illuminate\Console\Command;

class AnalyzeCodebaseHealth extends Command
{
    protected $signature = 'health:analyze {--project=}';

    protected $description = 'Queue proactive health analysis for enabled projects';

    public function handle(ProjectConfigService $projectConfigService): int
    {
        if (! (bool) config('health.enabled', true)) {
            $this->info('Health analysis is disabled by feature flag.');

            return self::SUCCESS;
        }

        $projectOption = $this->option('project');
        if ($projectOption !== null && $projectOption !== '') {
            $project = Project::query()->find((int) $projectOption);
            if (! $project instanceof Project) {
                $this->error('Project not found.');

                return self::FAILURE;
            }

            if (! $project->enabled) {
                $this->error('Project is disabled.');

                return self::FAILURE;
            }

            if (! (bool) $projectConfigService->get($project, 'health.enabled', true)) {
                $this->info("Project {$project->id} has health.enabled = false. Skipping.");

                return self::SUCCESS;
            }

            AnalyzeProjectHealth::dispatch($project->id);
            $this->info("Queued health analysis for project {$project->id}.");

            return self::SUCCESS;
        }

        $queued = 0;
        $projects = Project::query()->enabled()->get();
        foreach ($projects as $project) {
            if (! (bool) $projectConfigService->get($project, 'health.enabled', true)) {
                continue;
            }

            AnalyzeProjectHealth::dispatch($project->id);
            $queued++;
        }

        $this->info("Queued health analysis for {$queued} project".($queued === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
