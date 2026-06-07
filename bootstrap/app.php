<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The session cookie is a raw JWT shared with the Next.js app, and the
        // CSRF cookie must be readable by the SPA — keep Laravel from
        // encrypting/decrypting any of them.
        $middleware->encryptCookies(except: ['secure-exam-session', 'secure-exam-access', 'eb_csrf']);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\IssueCsrfCookie::class,
        ]);

        // Cookie-based auth ⇒ every state-changing API call must prove same-origin.
        $middleware->api(append: [
            \App\Http\Middleware\VerifyCsrfHeader::class,
        ]);

        $middleware->alias([
            'jwt.auth' => \App\Http\Middleware\JwtAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Alert on real server faults (5xx) so a live-exam failure is visible
        // instead of silent. 4xx/validation/auth/not-found are excluded.
        $exceptions->report(function (\Throwable $e) {
            if (\App\Services\Alerts::isAlertable($e)) {
                \App\Services\Alerts::send(
                    'Server error: '.class_basename($e).' — '.\Illuminate\Support\Str::limit($e->getMessage(), 140),
                    $e->getMessage()."\n".$e->getFile().':'.$e->getLine(),
                    ['type' => get_class($e)]
                );
            }
        });
    })->create();
