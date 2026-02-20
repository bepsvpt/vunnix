<?php

namespace App\Modules\Chat\Application\Contracts;

interface ChatMiddlewareProvider
{
    /**
     * @return array<int, mixed>
     */
    public function middleware(): array;
}
