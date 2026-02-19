<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCsrfUnlessTokenAuth extends Middleware
{
    public function handle($request, Closure $next): Response
    {
        if ($this->shouldBypassCsrf($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    protected function runningUnitTests(): bool
    {
        return parent::runningUnitTests() && ! (bool) config('security.force_csrf_validation_in_tests', false);
    }

    private function shouldBypassCsrf(Request $request): bool
    {
        if (($request->bearerToken() ?? '') !== '') {
            return true;
        }

        $route = $request->route();

        return $route !== null && in_array('task.token', $route->gatherMiddleware(), true);
    }
}
