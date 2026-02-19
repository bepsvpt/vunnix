<?php

namespace App\Services;

use App\Models\FindingAcceptance;
use App\Models\MemoryEntry;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MemoryExtractionService
{
    /**
     * @param  Collection<int, FindingAcceptance>  $acceptances
     * @return Collection<int, MemoryEntry>
     */
    public function extractFromFindings(Project $project, Collection $acceptances): Collection
    {
        if ($acceptances->isEmpty()) {
            return collect();
        }

        $entries = collect();
        $minSampleSize = (int) config('vunnix.memory.min_sample_size', 20);
        $acceptedStatuses = ['accepted', 'accepted_auto'];

        $totalFindings = $acceptances->count();
        $globalAcceptanceRate = $acceptances->whereIn('status', $acceptedStatuses)->count() / $totalFindings;

        foreach ($acceptances->groupBy(fn (FindingAcceptance $a): string => $a->category ?? 'uncategorized') as $category => $group) {
            $sampleSize = $group->count();
            if ($sampleSize < $minSampleSize) {
                continue;
            }

            $dismissalRate = $group->where('status', 'dismissed')->count() / $sampleSize;
            $acceptanceRate = $group->whereIn('status', $acceptedStatuses)->count() / $sampleSize;

            if ($dismissalRate <= 0.60) {
                continue;
            }

            $pattern = "Findings in category \"{$category}\" are frequently dismissed ({$this->toPercent($dismissalRate)} dismissal over {$sampleSize} samples).";

            if ($this->patternExists($project, 'review_pattern', 'false_positive', $pattern, $sampleSize)) {
                continue;
            }

            $entries->push($this->makeEntry(
                projectId: $project->id,
                type: 'review_pattern',
                category: 'false_positive',
                content: [
                    'pattern' => $pattern,
                    'category' => $category,
                    'acceptance_rate' => round($acceptanceRate, 3),
                    'dismissal_rate' => round($dismissalRate, 3),
                    'sample_size' => $sampleSize,
                    'example_titles' => $group->pluck('title')->filter()->unique()->take(3)->values()->all(),
                ],
                confidence: min(100, max(40, $sampleSize * 2)),
            ));
        }

        foreach ($acceptances->groupBy('severity') as $severity => $group) {
            $sampleSize = $group->count();
            if ($sampleSize < $minSampleSize) {
                continue;
            }

            $severityAcceptanceRate = $group->whereIn('status', $acceptedStatuses)->count() / $sampleSize;
            if (abs($severityAcceptanceRate - $globalAcceptanceRate) <= 0.20) {
                continue;
            }

            $pattern = "Severity \"{$severity}\" acceptance differs from baseline ({$this->toPercent($severityAcceptanceRate)} vs {$this->toPercent($globalAcceptanceRate)} global).";

            if ($this->patternExists($project, 'review_pattern', 'severity_calibration', $pattern, $sampleSize)) {
                continue;
            }

            $entries->push($this->makeEntry(
                projectId: $project->id,
                type: 'review_pattern',
                category: 'severity_calibration',
                content: [
                    'pattern' => $pattern,
                    'severity' => $severity,
                    'acceptance_rate' => round($severityAcceptanceRate, 3),
                    'global_acceptance_rate' => round($globalAcceptanceRate, 3),
                    'sample_size' => $sampleSize,
                    'example_titles' => $group->pluck('title')->filter()->unique()->take(3)->values()->all(),
                ],
                confidence: min(100, max(40, $sampleSize * 2)),
            ));
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return Collection<int, MemoryEntry>
     */
    public function extractFromConversationSummary(Project $project, string $summary, array $meta): Collection
    {
        $summary = trim($summary);
        if ($summary === '') {
            return collect();
        }

        $entries = collect();
        $sentences = preg_split('/(?<=[.!?])\s+/', $summary);
        if ($sentences === false) {
            $sentences = [];
        }
        $existingFacts = MemoryEntry::query()
            ->forProject($project->id)
            ->ofType('conversation_fact')
            ->active()
            ->pluck('content')
            ->map(fn ($content): string => (string) ($content['fact'] ?? ''))
            ->filter(fn (string $fact): bool => $fact !== '')
            ->values();

        foreach ($sentences as $sentence) {
            $fact = trim($sentence);
            if ($fact === '') {
                continue;
            }

            if (! $this->isTechnicalFact($fact)) {
                continue;
            }

            if ($this->isSimilarFact($fact, $existingFacts->all())) {
                continue;
            }

            $confidence = $this->hasSpecificTechReference($fact) ? 80 : 60;
            $entries->push($this->makeEntry(
                projectId: $project->id,
                type: 'conversation_fact',
                category: 'fact',
                content: [
                    'pattern' => $fact,
                    'fact' => $fact,
                ],
                confidence: $confidence,
                sourceMeta: [
                    'conversation_id' => $meta['conversation_id'] ?? null,
                ],
            ));
            $existingFacts->push($fact);
        }

        return $entries;
    }

    /**
     * @return Collection<int, MemoryEntry>
     */
    public function detectCrossMRPatterns(Project $project, int $lookbackDays = 60): Collection
    {
        $findings = FindingAcceptance::query()
            ->where('project_id', $project->id)
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->get();

        if ($findings->isEmpty()) {
            return collect();
        }

        $entries = collect();

        foreach ($findings->groupBy('file') as $file => $group) {
            $mrCount = $group->pluck('mr_iid')->unique()->filter()->count();
            if ($mrCount < 3) {
                continue;
            }

            $count = $group->count();
            $pattern = "File hotspot detected: {$file} was flagged {$count} times across {$mrCount} merge requests.";

            if ($this->patternExists($project, 'cross_mr_pattern', 'hotspot', $pattern, $count)) {
                continue;
            }

            $entries->push($this->makeEntry(
                projectId: $project->id,
                type: 'cross_mr_pattern',
                category: 'hotspot',
                content: [
                    'pattern' => $pattern,
                    'file' => $file,
                    'findings_count' => $count,
                    'mr_count' => $mrCount,
                ],
                confidence: min(100, max(40, $count * 10)),
            ));
        }

        foreach ($findings->whereNotNull('category')->groupBy('category') as $category => $group) {
            $mrCount = $group->pluck('mr_iid')->unique()->filter()->count();
            if ($mrCount < 3) {
                continue;
            }

            $count = $group->count();
            $pattern = "Category cluster: \"{$category}\" appears across {$mrCount} merge requests ({$count} findings).";
            if ($this->patternExists($project, 'cross_mr_pattern', 'convention', $pattern, $count)) {
                continue;
            }

            $entries->push($this->makeEntry(
                projectId: $project->id,
                type: 'cross_mr_pattern',
                category: 'convention',
                content: [
                    'pattern' => $pattern,
                    'category' => $category,
                    'findings_count' => $count,
                    'mr_count' => $mrCount,
                ],
                confidence: min(100, max(40, $count * 10)),
            ));
        }

        foreach ($findings->where('status', 'dismissed')->groupBy('title') as $title => $group) {
            $mrCount = $group->pluck('mr_iid')->unique()->filter()->count();
            if ($mrCount < 2) {
                continue;
            }

            $pattern = "Repeated dismissal pattern: \"{$title}\" was dismissed in {$mrCount} merge requests.";
            if ($this->patternExists($project, 'cross_mr_pattern', 'convention', $pattern, $group->count())) {
                continue;
            }

            $entries->push($this->makeEntry(
                projectId: $project->id,
                type: 'cross_mr_pattern',
                category: 'convention',
                content: [
                    'pattern' => $pattern,
                    'title' => $title,
                    'dismissed_count' => $group->count(),
                    'mr_count' => $mrCount,
                ],
                confidence: min(100, max(40, $group->count() * 15)),
            ));
        }

        return $entries;
    }

    /**
     * @param  array<array-key, mixed>  $content
     * @param  array<array-key, mixed>|null  $sourceMeta
     */
    private function makeEntry(
        int $projectId,
        string $type,
        ?string $category,
        array $content,
        int $confidence,
        ?int $sourceTaskId = null,
        ?array $sourceMeta = null,
    ): MemoryEntry {
        return new MemoryEntry([
            'project_id' => $projectId,
            'type' => $type,
            'category' => $category,
            'content' => $content,
            'confidence' => min(100, max(0, $confidence)),
            'source_task_id' => $sourceTaskId,
            'source_meta' => $sourceMeta,
        ]);
    }

    private function patternExists(
        Project $project,
        string $type,
        ?string $category,
        string $pattern,
        int $confidence,
    ): bool {
        return MemoryEntry::query()
            ->forProject($project->id)
            ->ofType($type)
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->whereNull('archived_at')
            ->where('confidence', '>=', $confidence)
            ->get()
            ->contains(function (MemoryEntry $entry) use ($pattern): bool {
                return (string) ($entry->content['pattern'] ?? '') === $pattern;
            });
    }

    private function toPercent(float $ratio): string
    {
        return round($ratio * 100).'%';
    }

    private function isTechnicalFact(string $text): bool
    {
        if (mb_strlen($text) < 20) {
            return false;
        }

        $hasDecisionVerb = (bool) preg_match('/\b(uses?|using|decided|chose|configured|structured|implemented|migrated|prefers?|adopted)\b/i', $text);
        if (! $hasDecisionVerb) {
            return false;
        }

        return $this->hasSpecificTechReference($text);
    }

    private function hasSpecificTechReference(string $text): bool
    {
        return (bool) preg_match(
            '/\b(laravel|php|vue|typescript|javascript|redis|postgres|mysql|sqlite|docker|queue|api|controller|service|model|migration|pipeline|oauth|gitlab|sse|webhook)\b/i',
            $text,
        ) || (bool) preg_match('/\b[\w\/.-]+\.(php|vue|ts|js|sh|md)\b/', $text);
    }

    /**
     * @param  array<int, string>  $existingFacts
     */
    private function isSimilarFact(string $candidate, array $existingFacts): bool
    {
        $normalizedCandidate = Str::lower($candidate);

        foreach ($existingFacts as $existing) {
            similar_text($normalizedCandidate, Str::lower($existing), $percent);
            if ($percent >= 85.0) {
                return true;
            }
        }

        return false;
    }
}
