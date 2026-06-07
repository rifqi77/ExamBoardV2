<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live operational snapshot for the admin System panel — the things that keep
 * exams running (DB, backups, scheduler, queue, AI, caches, alerting). This is
 * the runtime companion to the pre-flight `app:doctor` command.
 *
 * Status values: 'ok' | 'warn' | 'down'.
 */
class SystemHealth
{
    public function snapshot(): array
    {
        return [
            'generatedAt' => now()->toIso8601String(),
            'checks' => [
                $this->database(),
                $this->migrations(),
                $this->backup(),
                $this->scheduler(),
                $this->queue(),
                $this->aiProvider(),
                $this->opcache(),
                $this->alerting(),
            ],
        ];
    }

    private function row(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }

    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->row('db', 'Database', 'ok', DB::connection()->getDatabaseName());
        } catch (\Throwable $e) {
            return $this->row('db', 'Database', 'down', substr($e->getMessage(), 0, 80));
        }
    }

    private function migrations(): array
    {
        try {
            $m = app('migrator');
            $ran = $m->getRepository()->repositoryExists() ? $m->getRepository()->getRan() : [];
            $pending = count(array_diff(array_keys($m->getMigrationFiles([database_path('migrations')])), $ran));

            return $this->row('migrations', 'Migrations', $pending === 0 ? 'ok' : 'warn', $pending === 0 ? 'all applied' : $pending.' pending');
        } catch (\Throwable $e) {
            return $this->row('migrations', 'Migrations', 'warn', substr($e->getMessage(), 0, 60));
        }
    }

    private function backup(): array
    {
        $files = glob(storage_path('app/backups').DIRECTORY_SEPARATOR.'*.sql.gz') ?: [];
        if (! $files) {
            return $this->row('backup', 'Last backup', 'warn', 'none yet — run db:backup');
        }
        $age = time() - max(array_map('filemtime', $files));

        return $this->row('backup', 'Last backup', $age < 36 * 3600 ? 'ok' : 'warn', round($age / 3600, 1).'h ago · '.count($files).' kept');
    }

    private function scheduler(): array
    {
        $hb = storage_path('app/scheduler-heartbeat');
        if (! is_file($hb)) {
            return $this->row('scheduler', 'Scheduler', 'warn', 'no heartbeat — is schedule:run firing?');
        }
        $age = time() - filemtime($hb);

        return $this->row('scheduler', 'Scheduler', $age < 300 ? 'ok' : 'warn', $age < 300 ? 'tick '.$age.'s ago' : 'stale '.round($age / 60).'m');
    }

    private function queue(): array
    {
        $conn = (string) config('queue.default');
        try {
            $hasJobs = Schema::hasTable('jobs');
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $pending = $hasJobs ? DB::table('jobs')->count() : 0;
            if (! $hasJobs) {
                return $this->row('queue', 'Queue', 'warn', 'jobs table missing — run migrate');
            }

            return $this->row('queue', 'Queue', $failed > 0 ? 'warn' : 'ok', $conn.' · '.$pending.' pending · '.$failed.' failed');
        } catch (\Throwable $e) {
            return $this->row('queue', 'Queue', 'warn', substr($e->getMessage(), 0, 50));
        }
    }

    private function aiProvider(): array
    {
        try {
            $s = AiProviders::getSettings();
            $ready = $s['textProvider'] === 'pollinations' || (AiProviders::keyStatus()[$s['textProvider']] ?? false);

            return $this->row('ai', 'AI provider', $ready ? 'ok' : 'warn', $s['textProvider'].($ready ? ' · ready' : ' · no key'));
        } catch (\Throwable $e) {
            return $this->row('ai', 'AI provider', 'warn', substr($e->getMessage(), 0, 50));
        }
    }

    private function opcache(): array
    {
        $on = ini_get('opcache.enable');

        return $this->row('opcache', 'OPcache', $on ? 'ok' : 'warn', $on ? 'on' : 'off — enable for speed');
    }

    private function alerting(): array
    {
        $has = config('alerts.webhook_url') || config('alerts.email');

        return $this->row('alerting', 'Error alerting', $has ? 'ok' : 'warn', $has ? 'configured' : 'not configured');
    }
}
