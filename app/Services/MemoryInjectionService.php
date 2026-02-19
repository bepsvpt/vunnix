<?php

namespace App\Services;

use App\Models\MemoryEntry;
use App\Models\Project;
use Illuminate\Support\Collection;

class MemoryInjectionService
{
    public function __construct(
        private readonly ProjectMemoryService $projectMemoryService,
    ) {}

    public function buildReviewGuidance(Project $project): string
    {
        if (! $this->reviewLearningEnabled()) {
            return '';
        }

        $entries = $this->projectMemoryService->getActiveMemories($project)
            ->whereIn('type', ['review_pattern', 'cross_mr_pattern'])
            ->values();

        $reviewGuidance = $this->buildSection(
            $entries,
            static fn (MemoryEntry $entry): string => '- '.($entry->content['pattern'] ?? ''),
        );

        $healthGuidance = $this->buildHealthGuidance($project);

        return implode("\n", array_values(array_filter([$reviewGuidance, $healthGuidance], static fn (string $text): bool => $text !== '')));
    }

    public function buildHealthGuidance(Project $project): string
    {
        if (! (bool) config('health.enabled', true)) {
            return '';
        }

        $entries = $this->projectMemoryService->getActiveMemories($project, 'health_signal');

        return $this->buildSection(
            $entries,
            static function (MemoryEntry $entry): string {
                $signal = $entry->content['signal'] ?? $entry->content['details_summary'] ?? null;

                return is_string($signal) ? '- '.$signal : '';
            },
        );
    }

    public function buildConversationContext(Project $project): string
    {
        if (! $this->conversationContinuityEnabled()) {
            return '';
        }

        $entries = $this->projectMemoryService->getActiveMemories($project, 'conversation_fact');

        return $this->buildSection(
            $entries,
            static fn (MemoryEntry $entry): string => '- '.($entry->content['fact'] ?? $entry->content['pattern'] ?? ''),
        );
    }

    public function buildCrossMRContext(Project $project): string
    {
        if (! $this->crossMrEnabled()) {
            return '';
        }

        $entries = $this->projectMemoryService->getActiveMemories($project, 'cross_mr_pattern');

        return $this->buildSection(
            $entries,
            static fn (MemoryEntry $entry): string => '- '.($entry->content['pattern'] ?? ''),
        );
    }

    /**
     * @param  Collection<int, MemoryEntry>  $entries
     * @param  callable(MemoryEntry): string  $lineBuilder
     */
    private function buildSection(Collection $entries, callable $lineBuilder): string
    {
        if ($entries->isEmpty()) {
            return '';
        }

        $maxTokens = (int) config('vunnix.memory.max_context_tokens', 2000);
        $lines = [];
        $usedTokens = 0;

        foreach ($entries as $entry) {
            $line = trim($lineBuilder($entry));
            if ($line === '' || $line === '-') {
                continue;
            }

            $lineTokens = $this->estimateTokens($line);
            if ($usedTokens + $lineTokens > $maxTokens) {
                break;
            }

            $lines[] = $line;
            $usedTokens += $lineTokens;
            $this->projectMemoryService->recordApplied($entry);
        }

        return implode("\n", $lines);
    }

    private function estimateTokens(string $text): int
    {
        $words = preg_split('/\s+/', trim($text));
        if ($words === false) {
            $words = [];
        }

        return count(array_filter($words, static fn (string $word): bool => $word !== ''));
    }

    private function reviewLearningEnabled(): bool
    {
        return (bool) config('vunnix.memory.enabled', true)
            && (bool) config('vunnix.memory.review_learning', true);
    }

    private function conversationContinuityEnabled(): bool
    {
        return (bool) config('vunnix.memory.enabled', true)
            && (bool) config('vunnix.memory.conversation_continuity', true);
    }

    private function crossMrEnabled(): bool
    {
        return (bool) config('vunnix.memory.enabled', true)
            && (bool) config('vunnix.memory.cross_mr_patterns', true);
    }
}
