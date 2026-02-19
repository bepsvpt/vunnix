<?php

use App\Http\Controllers\Api\DeadLetterController;
use App\Services\DeadLetterService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('aborts dead letter index when no authenticated user is present', function (): void {
    $request = Request::create('/api/v1/admin/dead-letter', 'GET');
    $request->setUserResolver(static fn () => null);

    $controller = new DeadLetterController(new DeadLetterService);

    $thrown = null;

    try {
        $controller->index($request);
    } catch (HttpException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown?->getStatusCode())->toBe(401);
});
