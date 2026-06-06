<?php

namespace App\Services;

/**
 * AES-256-GCM at-rest secrets — byte-compatible with the Next.js app's
 * src/lib/crypto-secrets.ts. Same key derivation (SHA-256 over
 * "domain\0SESSION_SECRET"), same wire format
 * (base64(iv):base64(tag):base64(ciphertext)), same per-domain keys —
 * so token previews / student passwords written by either app are
 * readable by the other.
 */
class CryptoSecrets
{
    private const IV = 12;
    private const TAG = 16;

    private static function key(string $domain): string
    {
        $secret = env('SESSION_SECRET');
        if (! $secret || strlen($secret) < 16) {
            throw new \RuntimeException('SESSION_SECRET missing or too short — cannot derive key.');
        }
        return hash('sha256', $domain."\0".$secret, true);
    }

    private static function encryptWith(string $plain, string $domain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(self::IV);
        $tag = '';
        $enc = openssl_encrypt($plain, 'aes-256-gcm', self::key($domain), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG);
        return base64_encode($iv).':'.base64_encode($tag).':'.base64_encode($enc);
    }

    private static function decryptWith(?string $blob, string $domain): ?string
    {
        if (! $blob) {
            return null;
        }
        $parts = explode(':', $blob);
        if (count($parts) !== 3) {
            return null;
        }
        $iv = base64_decode($parts[0], true);
        $tag = base64_decode($parts[1], true);
        $enc = base64_decode($parts[2], true);
        if ($iv === false || $tag === false || $enc === false) {
            return null;
        }
        if (strlen($iv) !== self::IV || strlen($tag) !== self::TAG) {
            return null;
        }
        $dec = openssl_decrypt($enc, 'aes-256-gcm', self::key($domain), OPENSSL_RAW_DATA, $iv, $tag);
        return $dec === false ? null : $dec;
    }

    private static function looksEncrypted(string $v): bool
    {
        $parts = explode(':', $v);
        if (count($parts) !== 3) {
            return false;
        }
        foreach ($parts as $p) {
            if ($p === '' || ! preg_match('/^[A-Za-z0-9+\/]+=*$/', $p)) {
                return false;
            }
        }
        return true;
    }

    public static function encryptTokenPreview(string $p): string
    {
        return self::encryptWith($p, 'token-preview-v1');
    }

    public static function decryptTokenPreview(?string $b): ?string
    {
        return ($b !== null && self::looksEncrypted($b)) ? self::decryptWith($b, 'token-preview-v1') : $b;
    }

    public static function encryptSecret(string $p): string
    {
        return self::encryptWith($p, 'ai-keys-v1');
    }

    public static function decryptSecret(?string $b): ?string
    {
        return self::decryptWith($b, 'ai-keys-v1');
    }

    public static function encryptStudentPassword(string $p): string
    {
        return self::encryptWith($p, 'student-password-v1');
    }

    public static function decryptStudentPassword(?string $b): ?string
    {
        return ($b !== null && self::looksEncrypted($b)) ? self::decryptWith($b, 'student-password-v1') : $b;
    }
}
