<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Session JWT helper — byte-for-byte compatible with the Next.js app's
 * src/lib/session.ts. HS256 over the raw SESSION_SECRET string, claims
 * { uid, role, tv, iat, exp }. A cookie minted here is accepted by the
 * Next app and vice-versa, which lets the two run side by side during a
 * migration.
 */
class SessionJwt
{
    public const COOKIE = 'secure-exam-session';

    private static function secret(): string
    {
        $s = env('SESSION_SECRET');
        if (!$s || strlen($s) < 32) {
            throw new \RuntimeException('SESSION_SECRET missing or too short (need >= 32 chars).');
        }
        return $s;
    }

    public static function ttlSeconds(): int
    {
        $days = (int) env('SESSION_COOKIE_DAYS', 5);
        $days = max(1, min(30, $days));
        return $days * 24 * 60 * 60;
    }

    /**
     * Mint a session token with the same claim shape as jose's SignJWT.
     * When $impersonatorId is set (admin impersonating a teacher) an extra
     * `imp` claim carries the admin's own uid so the session can be
     * reverted. Non-impersonated tokens stay byte-identical to the Next app.
     */
    public static function sign(string $uid, string $role, int $tokenVersion, ?string $impersonatorId = null): string
    {
        $now = time();
        $claims = [
            'uid' => $uid,
            'role' => $role,
            'tv' => $tokenVersion,
            'iat' => $now,
            'exp' => $now + self::ttlSeconds(),
        ];
        if ($impersonatorId) {
            $claims['imp'] = $impersonatorId;
        }
        return JWT::encode($claims, self::secret(), 'HS256');
    }

    /** @return array{uid:string,role:string,tv:?int,imp:?string}|null */
    public static function verify(?string $token): ?array
    {
        if (!$token) {
            return null;
        }
        try {
            $d = JWT::decode($token, new Key(self::secret(), 'HS256'));
            if (!isset($d->uid, $d->role)) {
                return null;
            }
            return [
                'uid' => (string) $d->uid,
                'role' => (string) $d->role,
                'tv' => isset($d->tv) ? (int) $d->tv : null,
                'imp' => isset($d->imp) ? (string) $d->imp : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
