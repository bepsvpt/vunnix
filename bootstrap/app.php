<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        $middleware->redirectGuestsTo('/auth/redirect');
        $middleware->validateCsrfTokens(except: [
            'webhook',
        ]);
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\VerifyCsrfUnlessTokenAuth::class,
        ]);
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'webhook.verify' => \App\Http\Middleware\VerifyWebhookToken::class,
            'task.token' => \App\Http\Middleware\AuthenticateTaskToken::class,
            'api.key' => \App\Http\Middleware\AuthenticateApiKey::class,
            'auth.api_key_or_session' => \App\Http\Middleware\AuthenticateSessionOrApiKey::class,
            'revalidate' => \App\Http\Middleware\RevalidateGitLabMembership::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
