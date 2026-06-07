<?php

namespace App\Http\Middleware;

use App\Support\Csrf;
use Closure;
use Illuminate\Http\Request;

/**
 * Ensures every page load carries a readable CSRF cookie the SPA can echo back.
 * Runs in the web group (page responses). Issues a new token only when one is
 * missing or invalid, so multiple tabs share the same token.
 */
class IssueCsrfCookie
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! Csrf::valid($request->cookie(Csrf::COOKIE))) {
            // session cookie (minutes=0) so it always outlasts an exam;
            // httpOnly=false so the SPA can read and echo it.
            $response->headers->setCookie(cookie(
                Csrf::COOKIE,
                Csrf::issue(),
                0,
                '/',
                null,
                (bool) config('session.secure'),
                false,
                false,
                'lax'
            ));
        }

        return $response;
    }
}
