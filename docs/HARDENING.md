# Security & operations hardening

Three production safeguards are wired into the app. This is the operator guide.

## 1. HTTPS-only cookies

The session cookie (`secure-exam-session`) and exam-access cookie
(`secure-exam-access`) carry the auth/exam JWTs. Their `Secure` flag is driven
by `SESSION_SECURE_COOKIE`:

```ini
# .env (PRODUCTION, behind HTTPS)
SESSION_SECURE_COOKIE=true
APP_URL=https://your-domain
```

Keep it `false` for local `http://localhost`. With `true`, browsers send the
cookies only over TLS — required because SEB request-hash verification and the
JWT session both assume a trusted channel. After changing `.env` run
`php artisan config:clear`.

## 2. Login throttling + account lockout

`POST /api/auth/login` now enforces (no config needed):

- **Per-account lockout** — after **5** failed passwords an account is locked
  for **15 minutes** (`user_credentials.failed_attempts` / `locked_until`);
  during the lock even the correct password returns `423`.
- **Per-IP throttle** — more than **20** failed attempts/minute from one IP
  returns `429` until it cools down.
- A successful login clears both. Tune the constants in
  `app/Http/Controllers/AuthController.php` (`MAX_FAILED`, `LOCK_MINUTES`,
  `IP_MAX_PER_MIN`).

> If the app runs behind a reverse proxy, configure Laravel's TrustProxies so
> `$request->ip()` is the real client IP, not the proxy.

## 3. Database backups

```bash
php artisan db:backup            # gzipped dump -> storage/app/backups, keep 14 days
php artisan db:backup --keep=30  # keep 30 days
```

mysqldump is auto-detected (`C:\xampp\mysql\bin\mysqldump.exe` on Windows) or
set `DB_DUMP_BINARY` in `.env`. A nightly run at 01:00 is already scheduled in
`routes/console.php` — but Laravel's scheduler must actually be running:

- **Windows (XAMPP):** Task Scheduler → run every 1 minute:
  `C:\xampp\php\php.exe C:\xampp\htdocs\examboard\artisan schedule:run`
  (or a single daily task that runs `... artisan db:backup`).
- **Linux:** cron — `* * * * * php /path/artisan schedule:run >> /dev/null 2>&1`

**Restore** a backup:

```bash
gzip -dc storage/app/backups/secure_exam-YYYYMMDD-His.sql.gz | mysql -u root secure_exam
```

Test a restore into a scratch DB periodically — an untested backup isn't a backup.

## Also recommended (server config, not app code)

- **OPcache** — enable in `php.ini` (`opcache.enable=1`,
  `opcache.validate_timestamps=0` in prod) for a large PHP speedup.
- **Production caches** — `php artisan config:cache route:cache view:cache`
  (run `php artisan optimize`). Remember to clear them after deploys.
