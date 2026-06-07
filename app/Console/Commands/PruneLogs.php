<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bound the growth of append-only log tables. Audit records are integrity
 * evidence, so the default retention is a full year. Anti-cheat events are
 * NOT pruned here — they live on exam_sessions/submissions and are kept with
 * the score they belong to.
 *
 *   php artisan app:prune-logs                # keep 365 days of audit logs
 *   php artisan app:prune-logs --days=730     # keep 2 years
 */
class PruneLogs extends Command
{
    protected $signature = 'app:prune-logs {--days=365 : Delete audit_logs older than this many days}';

    protected $description = 'Prune old audit logs to bound database growth.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        if (! Schema::hasTable('audit_logs')) {
            $this->warn('audit_logs table not found — nothing to prune.');

            return self::SUCCESS;
        }

        $deleted = DB::table('audit_logs')->where('created_at', '<', $cutoff)->delete();
        $this->info("Pruned {$deleted} audit log(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
