<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Stateless, signed double-submit CSRF token.
 *
 * The token is `<random>.<hmac(random, APP_KEY)>`. It is delivered to the
 * browser in a readable (non-httpOnly) cookie; the SPA echoes it back in a
 * request header. CSRF safety comes from two independent facts:
 *   1. A cross-site page cannot READ the cookie to copy it into the header
 *      (same-origin policy), so it cannot produce a matching header.
 *   2. The HMAC means an attacker who can only WRITE cookies (e.g. via a
 *      sibling subdomain) still cannot forge a token without APP_KEY.
 *
 * No server-side state is kept, so it never expires mid-exam.
 */
class Csrf
{
    public const COOKIE = 'eb_csrf';

    public const HEADER = 'X-EB-Csrf';

    /** Mint a fresh signed token. */
    public static function issue(): string
    {
        $random = Str::random(40);

        return $random.'.'.self::sign($random);
    }

    /** True if the token is well-formed and its signature verifies. */
    public static function valid(?string $token): bool
    {
        if (! is_string($token) || ! str_contains($token, '.')) {
            return false;
        }
        [$random, $sig] = explode('.', $token, 2);
        if ($random === '' || $sig === '') {
            return false;
        }

        return hash_equals(self::sign($random), $sig);
    }

    /** True if cookie and header are present, equal, and individually valid. */
    public static function matches(?string $cookie, ?string $header): bool
    {
        return is_string($cookie) && is_string($header) && $cookie !== ''
            && hash_equals($cookie, $header)
            && self::valid($cookie);
    }

    private static function sign(string $random): string
    {
        return hash_hmac('sha256', $random, (string) config('app.key'));
    }
}
