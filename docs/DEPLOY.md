# Deploying Exam Dashboard (XAMPP)

Pure PHP/Apache + MariaDB. **Node is only needed to *build* the frontend once
on a dev machine — it is NOT needed on the production server**, which serves the
pre-built assets from `public/build/`.

## 1. Prerequisites (server)
- XAMPP with **PHP 8.2+** and **MariaDB/MySQL** (PHP exts: `pdo_mysql`,
  `openssl`, `mbstring`, `zip`; `gd` optional for image handling)
- Composer

## 2. Code + dependencies
```bash
# place the project at C:\xampp\htdocs\examboard
composer install --no-dev --optimize-autoloader
```

## 3. Configure `.env`
```bash
copy .env.example .env          # (cp on Linux)
php artisan key:generate
```
Then edit `.env`:
- `DB_DATABASE=secure_exam` (+ host/user/pass)
- **`SESSION_SECRET=`** — REQUIRED (≥32 chars). Generate:
  `php -r "echo bin2hex(random_bytes(33));"`
  (To share sessions with the Next.js app on the same DB, copy *its* value.)
- Production: `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL=https://…`, **`SESSION_SECURE_COOKIE=true`**

## 4. Database
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS secure_exam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate            # builds the schema (baseline + later migrations)
```
> Migrating onto the existing shared DB is already baselined — see
> `database/MIGRATIONS.md`.

**Create the first administrator** — a fresh database has no accounts to log in with:
```bash
php artisan app:create-admin                                   # interactive prompts
php artisan app:create-admin admin --name="School Admin" --password="change-me"   # scripted
```
Then sign in at `/login` and create teachers/students from the admin dashboard.

> **Bringing existing content over (optional).** The repo ships the *schema*, not
> data. To copy your current bank questions / learning objectives / exams to the
> new server, transfer a database dump separately (`php artisan db:backup` here →
> import there). A full dump also contains student accounts + submissions (PII) —
> move only what you intend to.

## 5. Build the frontend (once, on a machine with Node)
```bash
npm install
npm run build                  # outputs public/build/ — commit/ship this
```

## 6. Optimize + point Apache at /public
```bash
php artisan optimize           # config/route/view cache
```
Apache vhost `DocumentRoot` → `C:\xampp\htdocs\examboard\public`.
(After any later `.env`/route change: `php artisan optimize:clear`.)

## 7. Verify
```bash
php artisan app:doctor         # PASS/WARN/FAIL for every critical setting
```

## 8. Backups (schedule it)
`php artisan db:backup` runs nightly *if the scheduler runs* — set up Task
Scheduler / cron per `docs/HARDENING.md`.

## Related docs
- `docs/HARDENING.md` — HTTPS cookies, login lockout, backups, OPcache
- `docs/TESTING.md` — `php artisan test`
- `database/MIGRATIONS.md` — schema workflow

## Upgrades
```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm run build                  # if frontend changed (on a Node machine)
php artisan optimize:clear && php artisan optimize
php artisan app:doctor
```
