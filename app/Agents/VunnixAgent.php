<?php

namespace App\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

/**
 * Vunnix Conversation Engine agent.
 *
 * Placeholder implementation for T48 (SSE streaming endpoint).
 * T49 will add HasTools, HasMiddleware, HasStructuredOutput,
 * RememberConversation middleware, and the full system prompt.
 */
class VunnixAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You are Vunnix, an AI-powered development assistant. Help users with code review, project management, and software development tasks.';
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return 'claude-opus-4-20250514';
    }
}
