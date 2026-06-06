<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SessionJwt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Port of the Next app's /api/auth/login + session handling. Verifies a
 * bcrypt password (bcryptjs hashes validate unchanged under PHP's
 * password_verify) and issues the same HS256 cookie the Next app uses.
 *
 * Hardened with the Next app's protections: per-IP throttling (so a single
 * host can't brute-force) + per-account lockout via failed_attempts /
 * locked_until (so one account can't be hammered from rotating IPs).
 */
class AuthController extends Controller
{
    /** Failed attempts before an account is temporarily locked. */
    private const MAX_FAILED = 5;
    /** How long the lock lasts. */
    private const LOCK_MINUTES = 15;
    /** Max failed attempts per IP per minute before a hard 429. */
    private const IP_MAX_PER_MIN = 20;

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Per-IP throttle (counts only failed attempts; cleared on success).
        $ipKey = 'login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, self::IP_MAX_PER_MIN)) {
            $secs = RateLimiter::availableIn($ipKey);
            return response()->json(['error' => "Too many login attempts. Try again in {$secs} second(s)."], 429);
        }

        $user = User::where('username', $data['username'])->first();
        $invalid = response()->json(['error' => 'Invalid username or password.'], 401);

        if (!$user) {
            RateLimiter::hit($ipKey, 60);
            return $invalid;
        }
        if (!$user->active) {
            return response()->json(['error' => 'Account is deactivated.'], 403);
        }

        $cred = $user->credential;

        // Account lockout — refuse before even checking the password.
        if ($cred && $cred->locked_until && $cred->locked_until->isFuture()) {
            $mins = max(1, (int) ceil(now()->diffInSeconds($cred->locked_until) / 60));
            return response()->json(['error' => "Account temporarily locked after too many failed attempts. Try again in about {$mins} minute(s)."], 423);
        }

        // bcryptjs (the Next app's hasher) emits "$2b$" hashes; PHP's
        // password_get_info() reports those as "unknown", so Laravel's
        // Hash::check() throws on the prefix even though the algorithm is
        // identical. Normalize "$2b$" -> "$2y$" (byte-for-byte the same hash)
        // so the shared credentials validate here too.
        $hash = $cred?->password_hash;
        if (is_string($hash) && str_starts_with($hash, '$2b$')) {
            $hash = '$2y$'.substr($hash, 4);
        }
        if (!$hash || !Hash::check($data['password'], $hash)) {
            RateLimiter::hit($ipKey, 60);
            if ($cred) {
                $attempts = (int) $cred->failed_attempts + 1;
                $patch = ['failed_attempts' => $attempts];
                if ($attempts >= self::MAX_FAILED) {
                    $patch['failed_attempts'] = 0;
                    $patch['locked_until'] = now()->addMinutes(self::LOCK_MINUTES);
                }
                $cred->forceFill($patch)->save();
            }
            return $invalid;
        }

        // Success — clear throttle + lockout counters, record sign-in.
        RateLimiter::clear($ipKey);
        $cred->forceFill([
            'last_sign_in_at' => now(),
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $token = SessionJwt::sign($user->id, $user->role, (int) $user->token_version);

        return response()->json([
            'user' => $this->publicUser($user),
        ])->cookie(
            SessionJwt::COOKIE,
            $token,
            (int) (SessionJwt::ttlSeconds() / 60),
            '/',
            null,
            (bool) config('session.secure'), // HTTPS-only when SESSION_SECURE_COOKIE=true
            true,  // httpOnly
            false, // raw
            'lax'
        );
    }

    public function me(Request $request)
    {
        return response()->json($this->publicUser($request->attributes->get('authUser')));
    }

    public function logout(Request $request)
    {
        $user = $request->attributes->get('authUser');
        if ($user) {
            // Bump tokenVersion so the just-cleared cookie (and any other
            // outstanding ones) can never be replayed.
            $user->increment('token_version');
        }
        return response()->json(['ok' => true])
            ->cookie(SessionJwt::COOKIE, '', -1, '/');
    }

    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'fullName' => $user->full_name,
            'role' => $user->role,
            'active' => (bool) $user->active,
        ];
    }
}
