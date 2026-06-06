<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Dump the database to a gzipped .sql file under storage/app/backups and
 * prune old ones. Portable: defaults to XAMPP's mysqldump on Windows,
 * otherwise `mysqldump` on PATH; override with DB_DUMP_BINARY in .env.
 *
 *   php artisan db:backup            # keep 14 days
 *   php artisan db:backup --keep=30  # keep 30 days
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=14 : Delete backups older than this many days}';

    protected $description = 'Back up the MySQL/MariaDB database to a gzipped .sql file.';

    public function handle(): int
    {
        $conn = config('database.connections.'.config('database.default'));
        if (($conn['driver'] ?? null) !== 'mysql') {
            $this->error('db:backup supports the mysql driver only.');
            return self::FAILURE;
        }
        $db = (string) $conn['database'];
        $bin = $this->dumpBinary();

        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $sqlPath = $dir.DIRECTORY_SEPARATOR.$db.'-'.now()->format('Ymd-His').'.sql';

        $args = [$bin, '-h', (string) ($conn['host'] ?? '127.0.0.1'), '-P', (string) ($conn['port'] ?? 3306), '-u', (string) $conn['username']];
        if (($conn['password'] ?? '') !== '') {
            $args[] = '-p'.$conn['password'];
        }
        array_push($args, '--single-transaction', '--quick', '--routines', '--default-character-set=utf8mb4', $db);

        $this->info("Backing up '{$db}' …");
        $out = fopen($sqlPath, 'w');
        $proc = new Process($args);
        $proc->setTimeout(900);
        $proc->run(function ($type, $buffer) use ($out) {
            if ($type === Process::OUT) {
                fwrite($out, $buffer);
            } elseif (trim($buffer) !== '') {
                $this->warn(trim($buffer));
            }
        });
        fclose($out);

        if (! $proc->isSuccessful()) {
            @unlink($sqlPath);
            $this->error('mysqldump failed: '.trim($proc->getErrorOutput() ?: 'unknown error'));
            $this->line('If mysqldump is not on PATH, set DB_DUMP_BINARY in .env (e.g. C:\\xampp\\mysql\\bin\\mysqldump.exe).');
            return self::FAILURE;
        }

        $gzPath = $sqlPath.'.gz';
        file_put_contents($gzPath, gzencode((string) file_get_contents($sqlPath), 9));
        @unlink($sqlPath);

        $this->info('Saved '.$gzPath.' ('.number_format(filesize($gzPath) / 1024, 1).' KB)');
        $this->prune($dir, (int) $this->option('keep'));
        return self::SUCCESS;
    }

    private function dumpBinary(): string
    {
        if ($env = env('DB_DUMP_BINARY')) {
            return $env;
        }
        $xampp = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (PHP_OS_FAMILY === 'Windows' && is_file($xampp)) {
            return $xampp;
        }
        return 'mysqldump';
    }

    private function prune(string $dir, int $keepDays): void
    {
        if ($keepDays <= 0) {
            return;
        }
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $removed = 0;
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.sql.gz') ?: [] as $f) {
            if (filemtime($f) < $cutoff) {
                @unlink($f);
                $removed++;
            }
        }
        if ($removed) {
            $this->line("Pruned {$removed} backup(s) older than {$keepDays} day(s).");
        }
    }
}
