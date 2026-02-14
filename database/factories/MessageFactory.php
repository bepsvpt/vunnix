<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'agent' => '',
            'role' => 'user',
            'content' => fake()->paragraph(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => 'assistant']);
    }

    public function forConversation(Conversation $conversation): static
    {
        return $this->state(fn () => ['conversation_id' => $conversation->id]);
    }
}
