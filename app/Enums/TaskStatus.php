<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Superseded = 'superseded';

    /**
     * States from which no further transitions are allowed.
     */
    private const TERMINAL = [
        self::Completed,
        self::Failed,
        self::Superseded,
    ];

    /**
     * Allowed transitions: from → [to, to, ...].
     */
    private const TRANSITIONS = [
        'received' => ['queued'],
        'queued' => ['running', 'superseded', 'failed'],
        'running' => ['completed', 'failed', 'superseded'],
        'failed' => ['queued'], // retry
    ];

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::TRANSITIONS[$this->value] ?? [];

        return in_array($target->value, $allowed, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::TERMINAL, true);
    }
}
