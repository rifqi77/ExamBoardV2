<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Best-effort operational alerting so live-exam failures are visible instead of
 * silent. Always logs; additionally pings a webhook and/or emails an operator
 * if configured. Deduped so an outage doesn't flood the channel. Never throws —
 * alerting must not break the thing it is reporting on.
 */
class Alerts
{
    public static function send(string $subject, string $body = '', array $context = []): void
    {
        try {
            Log::error('[ALERT] '.$subject, ['body' => mb_substr($body, 0, 2000)] + $context);

            // Dedup: Cache::add is atomic — true only the first time within the window.
            $key = 'alert:'.md5($subject);
            $minutes = max(1, (int) config('alerts.throttle_minutes', 10));
            if (Cache::add($key, 1, now()->addMinutes($minutes))) {
                self::webhook($subject, $body);
                self::email($subject, $body);
            }
        } catch (\Throwable $e) {
            // swallow — never let alerting cascade
        }
    }

    /** Server faults only — not 4xx, validation, auth, or not-found. */
    public static function isAlertable(\Throwable $e): bool
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return false;
        }
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return false;
        }
        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return false;
        }
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }

        return true;
    }

    private static function webhook(string $subject, string $body): void
    {
        $url = (string) (config('alerts.webhook_url') ?? '');
        if ($url === '') {
            return;
        }
        try {
            Http::timeout(4)->post($url, [
                'text' => '⚠ '.config('app.name').": {$subject}".($body !== '' ? "\n".mb_substr($body, 0, 1500) : ''),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function email(string $subject, string $body): void
    {
        $to = (string) (config('alerts.email') ?? '');
        if ($to === '' || config('mail.default') === 'log') {
            return;
        }
        try {
            Mail::raw($body !== '' ? $body : $subject, function ($m) use ($to, $subject) {
                $m->to($to)->subject('[ExamBoard] '.$subject);
            });
        } catch (\Throwable $e) {
        }
    }
}
