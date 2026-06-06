<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Exam-access JWT — the narrower grant the Next app issues after a student
 * redeems a token (src/lib/session.ts signExamAccess). Authorises one user
 * for one exam. HS256 over SESSION_SECRET, claims { userId, examId,
 * tokenId, scope:'exam_access' }, 8h. Cookie: secure-exam-access.
 */
class ExamAccessJwt
{
    public const COOKIE = 'secure-exam-access';
    private const TTL = 8 * 60 * 60;

    private static function secret(): string
    {
        $s = env('SESSION_SECRET');
        if (! $s || strlen($s) < 32) {
            throw new \RuntimeException('SESSION_SECRET missing or too short.');
        }
        return $s;
    }

    public static function sign(string $userId, string $examId, string $tokenId): string
    {
        $now = time();
        return JWT::encode([
            'userId' => $userId,
            'examId' => $examId,
            'tokenId' => $tokenId,
            'scope' => 'exam_access',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + self::TTL,
        ], self::secret(), 'HS256');
    }

    /** @return array{userId:string,examId:string,tokenId:string}|null */
    public static function verify(?string $token): ?array
    {
        if (! $token) {
            return null;
        }
        try {
            $d = JWT::decode($token, new Key(self::secret(), 'HS256'));
            if (($d->scope ?? null) !== 'exam_access' || ! isset($d->userId, $d->examId, $d->tokenId)) {
                return null;
            }
            return [
                'userId' => (string) $d->userId,
                'examId' => (string) $d->examId,
                'tokenId' => (string) $d->tokenId,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public static function ttlMinutes(): int
    {
        return (int) (self::TTL / 60);
    }
}
