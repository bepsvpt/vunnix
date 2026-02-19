<?php

namespace App\Agents\Middleware;

use App\Agents\VunnixAgent;
use App\Jobs\ExtractConversationFacts;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

class PruneConversationHistory
{
    /**
     * Conversations exceeding this many turns trigger pruning.
     */
    public const TURN_THRESHOLD = 20;

    /**
     * Number of recent turns to keep in full (not summarized).
     */
    public const RECENT_TURNS_TO_KEEP = 10;

    public function __construct(
        protected ?TextProvider $provider = null,
    ) {}

    /**
     * Handle the incoming prompt.
     *
     * For conversations exceeding the turn threshold, summarize older messages
     * and keep the most recent turns in full. This reduces token usage by
     * 30-50% on long conversations while preserving context quality.
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $agent = $prompt->agent;

        if (! $agent instanceof VunnixAgent) {
            return $next($prompt);
        }

        $messages = $agent->messages();

        if (! is_array($messages)) {
            $messages = iterator_to_array($messages);
        }

        $turnCount = $this->countTurns($messages);

        if ($turnCount <= self::TURN_THRESHOLD) {
            return $next($prompt);
        }

        // Use the provider from the prompt if not injected via constructor
        $provider = $this->provider ?? $prompt->provider;

        $this->pruneMessages($agent, $messages, $provider);

        return $next($prompt);
    }

    /**
     * Count the number of turns in the message history.
     *
     * A turn is a user-assistant message pair. We count user messages
     * as the canonical turn indicator.
     *
     * @param  array<int, mixed>  $messages
     */
    protected function countTurns(array $messages): int
    {
        $turns = 0;

        foreach ($messages as $message) {
            $role = $message->role instanceof \Laravel\Ai\Messages\MessageRole
                ? $message->role->value
                : (string) $message->role;

            if ($role === 'user') {
                $turns++;
            }
        }

        return $turns;
    }

    /**
     * Prune the message history by summarizing older messages.
     *
     * @param  array<int, mixed>  $messages
     */
    protected function pruneMessages(VunnixAgent $agent, array $messages, TextProvider $provider): void
    {
        $recentMessageCount = self::RECENT_TURNS_TO_KEEP * 2; // 2 messages per turn
        $olderMessages = array_slice($messages, 0, count($messages) - $recentMessageCount);
        $recentMessages = array_slice($messages, -$recentMessageCount);

        try {
            $summary = $this->summarize($olderMessages, $provider);
            $this->dispatchConversationExtraction($agent, $summary);

            $summaryMessage = new UserMessage(
                "[Conversation Summary — earlier messages have been condensed]\n\n{$summary}"
            );

            $agent->setPrunedMessages([
                $summaryMessage,
                ...$recentMessages,
            ]);
        } catch (Throwable) {
            // Graceful degradation: if summarization fails, keep all messages.
            // This ensures the conversation continues even if the summarizer
            // API is unavailable — better to use more tokens than to break.
        }
    }

    /**
     * Summarize older messages using the cheapest available model.
     *
     * @param  array<int, mixed>  $messages
     */
    protected function summarize(array $messages, TextProvider $provider): string
    {
        $transcript = $this->formatMessagesForSummary($messages);

        $response = $provider->textGateway()->generateText(
            $provider,
            $provider->cheapestTextModel(),
            'You are a conversation summarizer. Summarize the following conversation history concisely. '
            .'Retain: user intent, decisions made, action items, and critical context. '
            .'Compress: verbose AI responses, repeated information, pleasantries. '
            .'Output a clear, structured summary in 2-4 paragraphs.',
            [new UserMessage($transcript)],
        );

        return $response->text;
    }

    /**
     * Format messages into a readable transcript for the summarizer.
     *
     * @param  array<int, mixed>  $messages
     */
    protected function formatMessagesForSummary(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $role = $message->role instanceof \Laravel\Ai\Messages\MessageRole
                ? $message->role->value
                : (string) $message->role;
            $label = $role === 'user' ? 'User' : 'Assistant';
            $content = Str::limit($message->content ?? '', 500);
            $lines[] = "[{$label}]: {$content}";
        }

        return implode("\n\n", $lines);
    }

    protected function dispatchConversationExtraction(VunnixAgent $agent, string $summary): void
    {
        try {
            $memoryEnabled = (bool) config('vunnix.memory.enabled', true);
            $continuityEnabled = (bool) config('vunnix.memory.conversation_continuity', true);
        } catch (Throwable) {
            return;
        }

        if (! $memoryEnabled || ! $continuityEnabled) {
            return;
        }

        $project = $agent->getProject();
        if (! $project instanceof \App\Models\Project) {
            return;
        }

        $conversationId = Context::get('vunnix_conversation_id');

        try {
            ExtractConversationFacts::dispatch(
                $summary,
                $project->id,
                [
                    'conversation_id' => is_string($conversationId) ? $conversationId : null,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('PruneConversationHistory: failed to dispatch conversation memory extraction', ['error' => $e->getMessage()]);
        }
    }
}
