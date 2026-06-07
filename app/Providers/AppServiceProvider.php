<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Named rate limiters used by the throttle middleware in routes/api.php.
     *
     * These are defense-in-depth on top of the per-account login lockout:
     * they bound abuse from a single IP / student / teacher per minute. The
     * critical autosave path (draft/events) is intentionally NOT throttled.
     */
    private function registerRateLimiters(): void
    {
        // Login: cap per IP (botnet floods one account) and per username
        // (distributed guesses at one account). 429 on either.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(10)->by('login-ip:'.$request->ip()),
                Limit::perMinute(20)->by('login-user:'.strtolower((string) $request->input('username'))),
            ];
        });

        // Exam-token validation is the only gate to an exam — brute-forceable.
        // Authenticated as a student, so key by user id (fall back to IP).
        RateLimiter::for('token', function (Request $request) {
            return Limit::perMinute(20)->by('token:'.$this->actorKey($request));
        });

        // AI endpoints cost CPU/credits — keep modest per actor.
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(10)->by('ai:'.$this->actorKey($request));
        });
    }

    /** Stable per-actor throttle key: authenticated user id, else client IP. */
    private function actorKey(Request $request): string
    {
        $user = $request->attributes->get('authUser');

        return $user ? (string) $user->id : (string) $request->ip();
    }
}
