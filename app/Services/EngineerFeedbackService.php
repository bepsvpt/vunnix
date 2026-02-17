<?php

namespace App\Services;

/**
 * Maps GitLab emoji reactions to engineer feedback sentiment.
 *
 * Per §16.3/D111: 👍 → positive, 👎 → negative, no reaction → neutral.
 * Sentiment is inferred from thread state when no explicit reactions exist.
 */
class EngineerFeedbackService
{
    /**
     * Classify award emoji reactions into a sentiment result.
     *
     * @param  array<int, array{name: string, user: array}>  $emoji
     * @return array{positive_count: int, negative_count: int, sentiment: string}
     */
    public function classifyReactions(array $emoji): array
    {
        $positive = 0;
        $negative = 0;

        foreach ($emoji as $reaction) {
            match ($reaction['name']) {
                'thumbsup' => $positive++,
                'thumbsdown' => $negative++,
                default => null,
            };
        }

        $sentiment = 'neutral';
        if ($positive > $negative) {
            $sentiment = 'positive';
        } elseif ($negative > $positive) {
            $sentiment = 'negative';
        }

        return [
            'positive_count' => $positive,
            'negative_count' => $negative,
            'sentiment' => $sentiment,
        ];
    }

    /**
     * Infer sentiment from thread acceptance state when no emoji exist.
     *
     * Per §16.3: "No reaction → neutral — infer from thread state."
     * All states map to neutral since the implicit signal is already
     * captured by the acceptance status field itself.
     */
    public function inferSentimentFromThreadState(string $status): string
    {
        return 'neutral';
    }
}
