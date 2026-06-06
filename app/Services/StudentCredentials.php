<?php

namespace App\Services;

/**
 * Port of src/lib/student-credentials.ts — memorable username/password
 * generators used by bulk reset (and roster import). Same nickname rule
 * (first non-title word, lowercased, accents stripped) and patterns
 * (<nickname><year> for passwords, <nickname><3-digit> for usernames).
 */
class StudentCredentials
{
    private const TITLE_SKIP = ['hj', 'h', 'dr', 'drs', 'dra', 'ir', 'prof', 'kh', 'haji', 'hajj', 'mr', 'mrs', 'ms'];

    private static function stripAccents(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t !== false ? $t : $s;
    }

    public static function extractNickname(string $fullName): string
    {
        $words = preg_split('/\s+/', trim($fullName)) ?: [];
        foreach ($words as $w) {
            $clean = preg_replace('/[^a-z0-9]/', '', strtolower(self::stripAccents($w)));
            if (strlen($clean) >= 2 && ! in_array($clean, self::TITLE_SKIP, true)) {
                return $clean;
            }
        }
        return 'siswa';
    }

    public static function generatePasswordFromName(string $fullName): string
    {
        $nick = substr(self::extractNickname($fullName), 0, 30);
        $base = strlen($nick) >= 2 ? $nick : 'siswa';
        return $base.date('Y');
    }

    /** @param array<string,bool> $taken  lowercased-username => true */
    public static function generateUsernameFromName(string $fullName, array $taken): string
    {
        $nick = substr(self::extractNickname($fullName), 0, 24);
        $base = strlen($nick) >= 2 ? $nick : 'siswa';
        for ($i = 0; $i < 99; $i++) {
            $cand = $base.str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
            if (! isset($taken[strtolower($cand)])) {
                return $cand;
            }
        }
        return $base.str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT).substr(bin2hex(random_bytes(2)), 0, 4);
    }
}
