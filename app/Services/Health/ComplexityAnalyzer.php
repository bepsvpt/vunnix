<?php

namespace App\Services\Health;

use App\Contracts\HealthAnalyzerContract;
use App\DTOs\HealthAnalysisResult;
use App\Enums\HealthDimension;
use App\Services\GitLabClient;
use Throwable;

class ComplexityAnalyzer implements HealthAnalyzerContract
{
    public function __construct(
        private readonly GitLabClient $gitLab,
    ) {}

    public function dimension(): HealthDimension
    {
        return HealthDimension::Complexity;
    }

    public function analyze(\App\Models\Project $project): ?HealthAnalysisResult
    {
        $ref = $this->resolveDefaultBranch($project->gitlab_project_id);
        $directories = config('health.analysis_directories', ['app/', 'resources/js/']);
        $maxFileReads = (int) config('health.max_file_reads', 20);

        $treeEntries = [];
        foreach ($directories as $directory) {
            if (! is_string($directory) || $directory === '') {
                continue;
            }

            try {
                $entries = $this->gitLab->listTree(
                    $project->gitlab_project_id,
                    trim($directory, '/'),
                    $ref,
                    true,
                );
            } catch (Throwable) {
                continue;
            }

            $treeEntries = [...$treeEntries, ...$entries];
        }

        /** @var array<int, array{id: string, name: string, type: string, path: string, mode: string}> $candidateFiles */
        $candidateFiles = array_values(array_filter(
            $treeEntries,
            fn (array $item): bool => $item['type'] === 'blob'
                && $this->isSupportedPath($item['path'])
        ));

        usort($candidateFiles, function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });

        $selectedFiles = array_slice($candidateFiles, 0, max(1, $maxFileReads));

        $filesAnalyzed = 0;
        $totalPenalty = 0;
        $totalLoc = 0;
        $hotspots = [];

        foreach ($selectedFiles as $file) {
            $path = $file['path'];

            $content = $this->readFile($project->gitlab_project_id, $path, $ref);
            if ($content === null) {
                continue;
            }

            $loc = $this->countLoc($content);
            $functionCount = $this->countFunctions($content, $path);
            $fileScore = 100;

            if ($loc > 500) {
                $totalPenalty += 5;
                $fileScore -= 25;
            } elseif ($loc > 300) {
                $totalPenalty += 3;
                $fileScore -= 15;
            }

            if ($functionCount > 20) {
                $totalPenalty += 3;
                $fileScore -= 15;
            }

            if ($loc > 300 || $functionCount > 20) {
                $hotspots[] = [
                    'path' => $path,
                    'loc' => $loc,
                    'function_count' => $functionCount,
                    'score' => max(0, $fileScore),
                ];
            }

            $filesAnalyzed++;
            $totalLoc += $loc;
        }

        if ($filesAnalyzed === 0) {
            return null;
        }

        usort($hotspots, fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        $score = max(0.0, 100.0 - $totalPenalty);

        return new HealthAnalysisResult(
            dimension: $this->dimension(),
            score: $score,
            details: [
                'hotspot_files' => array_slice($hotspots, 0, 10),
                'files_analyzed' => $filesAnalyzed,
                'avg_loc' => round($totalLoc / $filesAnalyzed, 1),
            ],
            sourceRef: $ref,
        );
    }

    private function isSupportedPath(string $path): bool
    {
        $lower = strtolower($path);

        return str_ends_with($lower, '.php')
            || str_ends_with($lower, '.js')
            || str_ends_with($lower, '.ts')
            || str_ends_with($lower, '.vue');
    }

    private function countLoc(string $content): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return 0;
        }

        return count(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    private function countFunctions(string $content, string $path): int
    {
        $isPhp = str_ends_with(strtolower($path), '.php');
        $pattern = $isPhp
            ? '/\bfunction\s+\w+/'
            : '/(\bfunction\s+\w+)|(\bconst\s+\w+\s*=\s*\(?.*?\)?\s*=>)/';

        $matches = [];
        preg_match_all($pattern, $content, $matches);

        return count($matches[0]);
    }

    private function readFile(int $gitlabProjectId, string $path, string $ref): ?string
    {
        try {
            $payload = $this->gitLab->getFile($gitlabProjectId, $path, $ref);
        } catch (Throwable) {
            return null;
        }

        $content = $payload['content'];
        if ($content === '') {
            return null;
        }

        $encoding = strtolower($payload['encoding']);
        if ($encoding === 'base64') {
            $decoded = base64_decode($content, true);

            return is_string($decoded) ? $decoded : null;
        }

        return $content;
    }

    private function resolveDefaultBranch(int $gitlabProjectId): string
    {
        try {
            $project = $this->gitLab->getProject($gitlabProjectId);
            $defaultBranch = $project['default_branch'] ?? null;
            if (is_string($defaultBranch) && $defaultBranch !== '') {
                return $defaultBranch;
            }
        } catch (Throwable) {
            // Fallback branch when metadata read fails.
        }

        return 'main';
    }
}
