<?php

namespace App\Modules\Chat\Application\Contracts;

interface ChatToolsetProvider
{
    /**
     * @return iterable<int, \Laravel\Ai\Contracts\Tool>
     */
    public function tools(): iterable;
}
