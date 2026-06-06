<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * AI text-generation providers, ported from src/lib/ai-providers.ts.
 * Keys live (encrypted, "ai-keys-v1") in app_config_ai.ai_keys — the same
 * blob the Next app reads/writes — with .env fallback. Calls each
 * provider's REST API (no SDKs).
 */
class AiProviders
{
    public const TEXT_PROVIDERS = ['pollinations', 'gemini', 'claude', 'openai'];

    public const IMAGE_PROVIDERS = ['off', 'pollinations', 'gemini', 'openai'];

    public const TEXT_MODELS = [
        'pollinations' => ['openai', 'openai-large', 'mistral', 'llama'],
        'gemini' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite'],
        'claude' => ['claude-sonnet-4-5', 'claude-haiku-4-5', 'claude-opus-4-1'],
        'openai' => ['gpt-4o', 'gpt-4o-mini', 'gpt-5'],
    ];

    public const PROVIDER_LABELS = [
        'pollinations' => 'Pollinations.ai (free, no key)',
        'gemini' => 'Google Gemini',
        'claude' => 'Anthropic Claude',
        'openai' => 'OpenAI',
    ];

    public static function defaultModelFor(string $p): string
    {
        return self::TEXT_MODELS[$p][0] ?? 'openai';
    }

    public static function getSettings(): array
    {
        $r = DB::table('app_config_ai')->where('id', 'ai')->first();
        $provider = $r->text_provider ?? 'pollinations';
        if (! in_array($provider, self::TEXT_PROVIDERS, true)) {
            $provider = 'pollinations';
        }
        $model = $r->text_model ?? self::defaultModelFor($provider);
        if (! in_array($model, self::TEXT_MODELS[$provider] ?? [], true)) {
            $model = self::defaultModelFor($provider);
        }
        return [
            'textProvider' => $provider,
            'textModel' => $model,
            'temperature' => isset($r->temperature) ? (float) $r->temperature : 0.7,
            'imageProvider' => $r->image_provider ?? 'off',
        ];
    }

    public static function saveSettings(array $s, string $by): void
    {
        DB::table('app_config_ai')->updateOrInsert(['id' => 'ai'], [
            'text_provider' => $s['textProvider'],
            'text_model' => $s['textModel'],
            'temperature' => $s['temperature'],
            'image_provider' => $s['imageProvider'],
            'updated_by' => $by,
            'updated_at' => now(),
        ]);
    }

    public static function saveKeys(array $patch, string $by): void
    {
        $raw = DB::table('app_config_ai')->where('id', 'ai')->value('ai_keys');
        $cur = $raw ? (json_decode($raw, true) ?: []) : [];
        foreach (['gemini', 'claude', 'openai'] as $p) {
            if (! array_key_exists($p, $patch)) {
                continue;
            }
            $v = $patch[$p];
            if ($v === null || $v === '') {
                unset($cur[$p]);
            } else {
                $cur[$p] = CryptoSecrets::encryptSecret(trim((string) $v));
            }
        }
        DB::table('app_config_ai')->updateOrInsert(['id' => 'ai'], [
            'ai_keys' => json_encode($cur), 'updated_by' => $by, 'updated_at' => now(),
        ]);
    }

    private static function envKey(string $p): ?string
    {
        $map = ['gemini' => 'GEMINI_API_KEY', 'claude' => 'ANTHROPIC_API_KEY', 'openai' => 'OPENAI_API_KEY'];
        $v = env($map[$p] ?? '');
        return $v ?: null;
    }

    public static function resolveKey(string $p): ?string
    {
        $raw = DB::table('app_config_ai')->where('id', 'ai')->value('ai_keys');
        $stored = $raw ? (json_decode($raw, true) ?: []) : [];
        if (! empty($stored[$p])) {
            $dec = CryptoSecrets::decryptSecret($stored[$p]);
            if ($dec) {
                return $dec;
            }
        }
        return self::envKey($p);
    }

    public static function keyStatus(): array
    {
        return [
            'gemini' => self::resolveKey('gemini') !== null,
            'claude' => self::resolveKey('claude') !== null,
            'openai' => self::resolveKey('openai') !== null,
        ];
    }

    /**
     * Resolve an image URL for a description. Pollinations renders the
     * image directly from the URL (no key, no round-trip, no disk), which
     * is the only image path that works cleanly on pure PHP/XAMPP — so we
     * use it whenever images are requested and the provider isn't "off".
     * (Gemini/OpenAI image models return base64 that would have to be
     * written to public storage; left as a future enhancement.)
     */
    public static function imageUrl(string $description, int $width = 768, int $height = 512): string
    {
        $prompt = trim($description);
        if ($prompt === '') {
            $prompt = 'exam figure';
        }
        $seed = abs(crc32($prompt)) % 100000;
        return 'https://image.pollinations.ai/prompt/'.rawurlencode($prompt)
            ."?width={$width}&height={$height}&nologo=true&model=flux&seed={$seed}";
    }

    public static function generateText(string $prompt, bool $json, array $settings): string
    {
        return match ($settings['textProvider']) {
            'pollinations' => self::callPollinations($prompt, $json, $settings['textModel'], (float) $settings['temperature']),
            'gemini' => self::callGemini($prompt, $json, $settings['textModel'], (float) $settings['temperature']),
            'claude' => self::callClaude($prompt, $settings['textModel'], (float) $settings['temperature']),
            'openai' => self::callOpenAi($prompt, $json, $settings['textModel'], (float) $settings['temperature']),
            default => throw new \RuntimeException('Unknown provider.'),
        };
    }

    private static function callPollinations($prompt, $json, $model, $temp): string
    {
        $body = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => $temp, 'private' => true];
        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        $res = Http::timeout(240)->post('https://text.pollinations.ai/openai', $body);
        if (! $res->successful()) {
            throw new \RuntimeException('Pollinations failed ('.$res->status().').');
        }
        return $res->json('choices.0.message.content') ?? '';
    }

    private static function callGemini($prompt, $json, $model, $temp): string
    {
        $key = self::resolveKey('gemini');
        if (! $key) {
            throw new \RuntimeException('No Gemini API key configured.');
        }
        $cfg = ['temperature' => $temp];
        if ($json) {
            $cfg['responseMimeType'] = 'application/json';
        }
        $res = Http::timeout(240)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
            ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => $cfg]
        );
        if (! $res->successful()) {
            throw new \RuntimeException('Gemini failed ('.$res->status().').');
        }
        return $res->json('candidates.0.content.parts.0.text') ?? '';
    }

    private static function callClaude($prompt, $model, $temp): string
    {
        $key = self::resolveKey('claude');
        if (! $key) {
            throw new \RuntimeException('No Claude API key configured.');
        }
        $res = Http::timeout(240)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model, 'max_tokens' => 4096, 'temperature' => $temp,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Claude failed ('.$res->status().').');
        }
        return $res->json('content.0.text') ?? '';
    }

    private static function callOpenAi($prompt, $json, $model, $temp): string
    {
        $key = self::resolveKey('openai');
        if (! $key) {
            throw new \RuntimeException('No OpenAI API key configured.');
        }
        $body = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => $temp];
        if ($json) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        $res = Http::timeout(240)->withToken($key)->post('https://api.openai.com/v1/chat/completions', $body);
        if (! $res->successful()) {
            throw new \RuntimeException('OpenAI failed ('.$res->status().').');
        }
        return $res->json('choices.0.message.content') ?? '';
    }
}
