<?php

namespace App\Services;

/**
 * Per-teacher capability registry — mirrors the Next app's
 * src/lib/capabilities.ts keys (stored on users.capabilities, already a
 * JSON map). Admins always pass. A teacher with no map (null) defaults to
 * fully-enabled; explicit `false` entries restrict that feature.
 */
class Capabilities
{
    /** Grouped registry: section => [key => label]. */
    public const REGISTRY = [
        'AI' => [
            'ai.generate' => 'Use AI question generation',
        ],
        'Exam settings' => [
            'exam.config.duration' => 'Set duration',
            'exam.config.passingGrade' => 'Set passing grade',
            'exam.config.mode' => 'Set exam mode',
            'exam.config.shuffleQuestions' => 'Shuffle questions',
            'exam.config.shuffleOptions' => 'Shuffle options',
            'exam.config.language' => 'Set language',
            'exam.config.seb' => 'Configure Safe Exam Browser',
        ],
        'Question types' => [
            'exam.param.type.single' => 'Single choice',
            'exam.param.type.multi' => 'Multi select',
            'exam.param.type.short_text' => 'Short text',
            'exam.param.type.numeric' => 'Numeric',
            'exam.param.type.essay' => 'Essay',
        ],
        'Difficulties' => [
            'exam.param.difficulty.easy' => 'Easy',
            'exam.param.difficulty.medium' => 'Medium',
            'exam.param.difficulty.hard' => 'Hard',
            'exam.param.difficulty.hots' => 'HOTS',
            'exam.param.difficulty.olympiad' => 'Olympiad',
        ],
        'Media' => [
            'exam.param.media.image' => 'Images',
            'exam.param.media.table' => 'Tables',
        ],
    ];

    /** Flat list of all known capability keys. */
    public static function keys(): array
    {
        $out = [];
        foreach (self::REGISTRY as $group) {
            foreach ($group as $key => $_label) {
                $out[] = $key;
            }
        }
        return $out;
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** Does the user have a capability? Admin = always; unset map = default on. */
    public static function has($user, string $key): bool
    {
        if (($user->role ?? null) === 'admin') {
            return true;
        }
        $map = is_array($user->capabilities ?? null) ? $user->capabilities : [];
        if (empty($map)) {
            return true; // no explicit restrictions
        }
        return (bool) ($map[$key] ?? true);
    }

    /** Merge a stored (partial) map with defaults so every key is present. */
    public static function fill(?array $map): array
    {
        $map = is_array($map) ? $map : [];
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = array_key_exists($key, $map) ? (bool) $map[$key] : true;
        }
        return $out;
    }

    /** Keep only valid keys, coerced to bool. */
    public static function sanitize(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            if (self::isValidKey((string) $k)) {
                $out[(string) $k] = (bool) $v;
            }
        }
        return $out;
    }
}
