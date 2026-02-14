<?php

namespace App\Enums;

enum TaskPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function runnerQueueName(): string
    {
        return 'vunnix-runner-' . $this->value;
    }
}
