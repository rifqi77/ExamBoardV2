<?php

namespace App\Http\Middleware;

use App\Support\Csrf;
use Closure;
use Illuminate\Http\Request;

/**
 * Verifies the double-submit CSRF token on state-changing API requests.
 * Auth is cookie-based, so without this a cross-site page could ride the
 * session cookie. Safe methods and the test runner are exempt (mirrors
 * Laravel's own VerifyCsrfToken behaviour under PHPUnit).
 */
class VerifyCsrfHeader
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next)
    {
        if (app()->runningUnitTests() || in_array($request->method(), self::READ_METHODS, true)) {
            return $next($request);
        }

        if (! Csrf::matches($request->cookie(Csrf::COOKIE), $request->header(Csrf::HEADER))) {
            return response()->json([
                'error' => 'Your session token expired. Please refresh the page and try again.',
            ], 419);
        }

        return $next($request);
    }
}
