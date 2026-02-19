<?php

namespace App\Services;

use App\Models\MemoryEntry;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProjectMemoryService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return Collection<int, MemoryEntry>
     */
    public function getActiveMemories(Project $project, ?string $type = null): Collection
    {
        if (! $this->memoryAvailable()) {
            return collect();
        }

        $cacheKey = $this->cacheKey($project->id, $type);

        /** @var Collection<int, MemoryEntry> $entries */
        $entries = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            function () use ($project, $type): Collection {
                $query = MemoryEntry::query()
                    ->forProject($project->id)
                    ->active()
                    ->highConfidence((int) config('vunnix.memory.min_confidence', 40))
                    ->orderByDesc('confidence')
                    ->orderByDesc('created_at');

                if ($type !== null && $type !== '') {
                    $query->ofType($type);
                }

                return $query->get();
            },
        );

        return $entries;
    }

    public function archiveExpired(Project $project): int
    {
        if (! $this->memoryAvailable()) {
            return 0;
        }

        $retentionDays = (int) config('vunnix.memory.retention_days', 90);
        $cutoff = now()->subDays($retentionDays);

        $count = MemoryEntry::query()
            ->forProject($project->id)
            ->whereNull('archived_at')
            ->where('created_at', '<', $cutoff)
            ->update(['archived_at' => now()]);

        $this->invalidateProjectCache($project->id);

        return $count;
    }

    public function recordApplied(MemoryEntry $entry): void
    {
        if (! $this->memoryAvailable()) {
            return;
        }

        $entry->increment('applied_count');
    }

    public function deleteEntry(MemoryEntry $entry): void
    {
        if (! $this->memoryAvailable()) {
            return;
        }

        $entry->archived_at = now();
        $entry->save();

        $this->invalidateProjectCache($entry->project_id);
    }

    /**
     * @return array{
     *   total_entries: int,
     *   by_type: array<string, int>,
     *   by_category: array<string, int>,
     *   average_confidence: float,
     *   last_created_at: string|null
     * }
     */
    public function getStats(Project $project): array
    {
        if (! $this->memoryAvailable()) {
            return [
                'total_entries' => 0,
                'by_type' => [],
                'by_category' => [],
                'average_confidence' => 0.0,
                'last_created_at' => null,
            ];
        }

        $base = MemoryEntry::query()
            ->forProject($project->id)
            ->active();

        $total = (clone $base)->count();
        $byType = (clone $base)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $byCategory = (clone $base)
            ->whereNotNull('category')
            ->selectRaw('category, count(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $averageConfidence = (float) ((clone $base)->avg('confidence') ?? 0);
        $lastCreatedAtRaw = (clone $base)->max('created_at');
        $lastCreatedAt = $lastCreatedAtRaw !== null
            ? Carbon::parse($lastCreatedAtRaw)->toIso8601String()
            : null;

        return [
            'total_entries' => $total,
            'by_type' => $byType,
            'by_category' => $byCategory,
            'average_confidence' => round($averageConfidence, 2),
            'last_created_at' => $lastCreatedAt,
        ];
    }

    public function invalidateProjectCache(int $projectId): void
    {
        Cache::forget($this->cacheKey($projectId, null));
        Cache::forget($this->cacheKey($projectId, 'review_pattern'));
        Cache::forget($this->cacheKey($projectId, 'conversation_fact'));
        Cache::forget($this->cacheKey($projectId, 'cross_mr_pattern'));
        Cache::forget($this->cacheKey($projectId, 'health_signal'));
    }

    private function memoryAvailable(): bool
    {
        if (! (bool) config('vunnix.memory.enabled', true)) {
            return false;
        }

        try {
            return Schema::hasTable('memory_entries');
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheKey(int $projectId, ?string $type): string
    {
        $cacheType = $type !== null && $type !== '' ? $type : 'all';

        return "project_memory:{$projectId}:{$cacheType}";
    }
}
