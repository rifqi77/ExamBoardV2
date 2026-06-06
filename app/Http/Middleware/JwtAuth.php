<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SessionJwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirror of the Next app's requireRole/getCurrentSession: verify the JWT
 * cookie, resolve the live user row, enforce the tokenVersion match
 * (instant invalidation on logout / password reset / deactivate), and
 * optionally gate by role. The authenticated user is attached to the
 * request as `authUser`.
 *
 * Usage: ->middleware('jwt.auth')  or  ->middleware('jwt.auth:teacher,admin')
 */
class JwtAuth
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // API routes get a JSON error; browser (web) routes get redirected
        // to the login page.
        $deny = function (string $msg, int $code) use ($request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => $msg], $code);
            }
            return redirect('/login');
        };

        $claims = SessionJwt::verify($request->cookie(SessionJwt::COOKIE));
        if (!$claims) {
            return $deny('Not authenticated.', 401);
        }

        $user = User::find($claims['uid']);
        if (!$user || !$user->active) {
            return $deny('Account not found or inactive.', 401);
        }

        // tokenVersion snapshot in the JWT must match the live column.
        if ($claims['tv'] !== null && (int) $user->token_version !== $claims['tv']) {
            return $deny('Session expired, sign in again.', 401);
        }

        if (!empty($roles) && !in_array($user->role, $roles, true)) {
            return $deny('Forbidden.', 403);
        }

        $request->attributes->set('authUser', $user);
        $request->attributes->set('impersonatorId', $claims['imp'] ?? null);
        return $next($request);
    }
}
