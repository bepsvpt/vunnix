<?php

namespace App\Exceptions;

use App\Enums\TaskStatus;
use RuntimeException;

class InvalidTaskTransitionException extends RuntimeException
{
    public function __construct(
        public readonly TaskStatus $from,
        public readonly TaskStatus $to,
    ) {
        parent::__construct("Invalid task transition: {$from->value} → {$to->value}");
    }
}
