<?php

use App\Http\Controllers\Api\DeadLetterController;
use App\Services\DeadLetterService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('aborts dead letter index when authenticated user is not global admin', function (): void {
    $request = Request::create('/api/v1/admin/dead-letter', 'GET');
    $request->setUserResolver(static fn () => new class
    {
        public function isGlobalAdmin(): bool
        {
            return false;
        }
    });

    $controller = new DeadLetterController(new DeadLetterService);

    $thrown = null;

    try {
        $controller->index($request);
    } catch (HttpException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown?->getStatusCode())->toBe(403);
});
