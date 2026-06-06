<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bootstrap the first administrator on a fresh install (after `migrate`,
 * when the database has no users yet).
 *
 *   php artisan app:create-admin                       # interactive prompts
 *   php artisan app:create-admin admin --name="Head" --password=secret123
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {username?} {--name=} {--password=}';

    protected $description = 'Create an administrator account (first-time setup).';

    public function handle(): int
    {
        $username = trim((string) ($this->argument('username') ?: $this->ask('Admin username')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Full name', 'Administrator')));
        $password = (string) ($this->option('password') ?: $this->secret('Password (min 8 chars)'));

        if (! preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
            $this->error('Username must be 3–32 chars: letters, digits, dots, dashes, underscores.');
            return self::FAILURE;
        }
        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }
        if (User::where('username', $username)->exists()) {
            $this->error("A user named '{$username}' already exists.");
            return self::FAILURE;
        }

        $id = (string) Str::uuid();
        User::create([
            'id' => $id,
            'username' => $username,
            'full_name' => $name !== '' ? $name : 'Administrator',
            'role' => 'admin',
            'active' => true,
        ]);
        UserCredential::create([
            'user_id' => $id,
            'password_hash' => Hash::make($password),
            'password_set_at' => now(),
            'failed_attempts' => 0,
        ]);

        $this->info("Administrator '{$username}' created. Sign in at /login.");
        return self::SUCCESS;
    }
}
