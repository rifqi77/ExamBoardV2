<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment health check. Run after setup / before going live:
 *   php artisan app:doctor
 * Returns a non-zero exit code if any critical check FAILS.
 */
class Doctor extends Command
{
    protected $signature = 'app:doctor';

    protected $description = 'Verify the deployment is configured correctly (DB, secrets, migrations, build, backups).';

    private array $rows = [];
    private bool $hasFail = false;

    public function handle(): int
    {
        // Runtime.
        $this->check('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'PASS' : 'FAIL', PHP_VERSION);
        foreach (['pdo_mysql', 'openssl', 'mbstring', 'zip'] as $ext) {
            $this->check("ext: {$ext}", extension_loaded($ext) ? 'PASS' : 'FAIL', extension_loaded($ext) ? '' : 'required, not loaded');
        }
        $this->check('ext: gd (figures)', extension_loaded('gd') ? 'PASS' : 'WARN', extension_loaded('gd') ? '' : 'optional');

        // Database.
        try {
            DB::connection()->getPdo();
            $this->check('Database connection', 'PASS', DB::connection()->getDatabaseName());
        } catch (\Throwable $e) {
            $this->check('Database connection', 'FAIL', substr($e->getMessage(), 0, 80));
        }

        // Least-privilege DB account (root/no-password is the unsafe default).
        $dbUser = (string) config('database.connections.'.config('database.default').'.username');
        $dbPass = (string) config('database.connections.'.config('database.default').'.password');
        $this->check('DB least-privilege user', ($dbUser !== 'root' && $dbPass !== '') ? 'PASS' : 'WARN', $dbUser === 'root'
            ? 'connected as root — run database/sql/create-db-user.sql'
            : ($dbPass === '' ? "user '{$dbUser}' has empty password" : "user '{$dbUser}'"));

        // Secrets.
        $this->check('APP_KEY set', config('app.key') ? 'PASS' : 'FAIL', config('app.key') ? '' : 'run: php artisan key:generate');
        $secret = (string) env('SESSION_SECRET');
        $this->check('SESSION_SECRET (>=32)', strlen($secret) >= 32 ? 'PASS' : 'FAIL', strlen($secret) ? strlen($secret).' chars' : 'missing — JWT auth will not work');

        // Migrations.
        try {
            $migrator = app('migrator');
            $ran = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
            $pending = array_diff($files, $ran);
            $this->check('Migrations applied', count($pending) === 0 ? 'PASS' : 'WARN', count($pending) ? count($pending).' pending — run php artisan migrate' : 'all applied');
        } catch (\Throwable $e) {
            $this->check('Migrations applied', 'WARN', substr($e->getMessage(), 0, 60));
        }

        // Frontend build.
        $this->check('Frontend build present', is_file(public_path('build/manifest.json')) ? 'PASS' : 'FAIL', is_file(public_path('build/manifest.json')) ? '' : 'run: npm run build');

        // Storage.
        $this->check('storage/ writable', is_writable(storage_path()) ? 'PASS' : 'FAIL', is_writable(storage_path()) ? '' : 'fix permissions');

        // Secure cookies in production.
        $prod = app()->environment('production') || str_starts_with((string) config('app.url'), 'https');
        $secure = (bool) config('session.secure');
        $this->check('Secure cookies (prod)', (! $prod || $secure) ? 'PASS' : 'WARN', ($prod && ! $secure) ? 'set SESSION_SECURE_COOKIE=true behind HTTPS' : ($prod ? 'on' : 'n/a (local http)'));

        // Backups.
        $bin = env('DB_DUMP_BINARY') ?: (PHP_OS_FAMILY === 'Windows' && is_file('C:\\xampp\\mysql\\bin\\mysqldump.exe') ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump');
        $this->check('mysqldump (db:backup)', is_file($bin) ? 'PASS' : 'WARN', is_file($bin) ? $bin : "'{$bin}' not found — set DB_DUMP_BINARY");
        $appDir = storage_path('app');
        $this->check('backups dir writable', is_writable($appDir) ? 'PASS' : 'WARN', is_writable($appDir) ? '' : 'storage/app not writable');

        // Most recent backup age.
        $backups = glob($appDir.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'*.sql.gz') ?: [];
        if ($backups) {
            $age = time() - max(array_map('filemtime', $backups));
            $this->check('Recent backup (<36h)', $age < 36 * 3600 ? 'PASS' : 'WARN', 'newest '.round($age / 3600, 1).'h ago, '.count($backups).' kept');
        } else {
            $this->check('Recent backup (<36h)', 'WARN', 'none yet — run php artisan db:backup');
        }

        // Scheduler liveness (heartbeat written every minute by schedule:run).
        $hb = storage_path('app/scheduler-heartbeat');
        $hbAge = is_file($hb) ? time() - filemtime($hb) : null;
        $this->check('Scheduler running', ($hbAge !== null && $hbAge < 300) ? 'PASS' : 'WARN', $hbAge === null
            ? 'no heartbeat — register schedule:run (docs/OPERATIONS.md)'
            : ($hbAge < 300 ? 'last tick '.$hbAge.'s ago' : 'stale ('.round($hbAge / 60).'m) — is schedule:run firing?'));

        // Queue infrastructure (used by async AI jobs).
        $qc = (string) config('queue.default');
        try {
            $hasJobs = Schema::hasTable('jobs');
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $pending = $hasJobs ? DB::table('jobs')->count() : 0;
            $this->check('Queue ('.$qc.')', $hasJobs ? ($failed === 0 ? 'PASS' : 'WARN') : 'WARN', $hasJobs
                ? $pending.' pending, '.$failed.' failed'
                : 'jobs table missing — run php artisan migrate');
        } catch (\Throwable $e) {
            $this->check('Queue ('.$qc.')', 'WARN', substr($e->getMessage(), 0, 50));
        }

        $this->newLine();
        $this->table(['Status', 'Check', 'Detail'], array_map(fn ($r) => [$this->icon($r[0]), $r[1], $r[2]], $this->rows));

        if ($this->hasFail) {
            $this->error('FAILED — fix the items marked FAIL before serving exams.');
            return self::FAILURE;
        }
        $this->info('All critical checks passed.');
        return self::SUCCESS;
    }

    private function check(string $name, string $status, string $detail = ''): void
    {
        if ($status === 'FAIL') {
            $this->hasFail = true;
        }
        $this->rows[] = [$status, $name, $detail];
    }

    private function icon(string $s): string
    {
        return match ($s) {
            'PASS' => '<info>PASS</info>',
            'WARN' => '<comment>WARN</comment>',
            'FAIL' => '<error>FAIL</error>',
            default => $s,
        };
    }
}
