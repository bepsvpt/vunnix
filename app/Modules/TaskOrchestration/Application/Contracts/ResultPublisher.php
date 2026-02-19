<?php

namespace App\Modules\TaskOrchestration\Application\Contracts;

use App\Models\Task;

interface ResultPublisher
{
    /**
     * Higher value means earlier evaluation.
     */
    public function priority(): int;

    public function supports(Task $task): bool;

    public function publish(Task $task): void;
}
