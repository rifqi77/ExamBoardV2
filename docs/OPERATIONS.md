# Operations runbook

Day-to-day operation of the ExamBoard server. Pairs with `docs/DEPLOY.md`
(first-time setup) and `docs/HARDENING.md` (security posture).

## 1. Background processes you must keep running

The app needs **two** recurring processes. Without them, backups never run and
async AI work never completes.

| Process | What it does | How often |
|---|---|---|
| `php artisan schedule:run` | nightly DB backup, weekly log prune, heartbeat | **every minute** |
| `php artisan queue:work`   | processes async AI grading / generation jobs | **always on** |

Two helper scripts are provided in `bin/`:

- `bin/eb-schedule.cmd` — one scheduler tick (call every minute)
- `bin/eb-worker.cmd` — the long-running worker (auto-restarts itself)

### Register them with Windows Task Scheduler

Run an elevated `cmd` from the project root and adjust the path:

```bat
REM Scheduler — every minute, whether or not a user is logged in
schtasks /Create /TN "ExamBoard Scheduler" /SC MINUTE /MO 1 ^
  /TR "C:\xampp\htdocs\examboard\bin\eb-schedule.cmd" /RU SYSTEM /F

REM Queue worker — at startup, kept alive by the script's own loop
schtasks /Create /TN "ExamBoard Worker" /SC ONSTART ^
  /TR "C:\xampp\htdocs\examboard\bin\eb-worker.cmd" /RU SYSTEM /F
```

Start the worker now (don't wait for a reboot):

```bat
schtasks /Run /TN "ExamBoard Worker"
```

> Prefer a real service? Install [NSSM](https://nssm.cc/) and point it at
> `bin/eb-worker.cmd` for automatic restart + logging.

### Verify they're alive

```bat
php artisan app:doctor
```

Look for **Scheduler running** (heartbeat < 5 min) and **Queue** (0 failed).
If "Scheduler running" is WARN, the minute task isn't firing — re-check the
Task Scheduler entry and that `php` is on PATH for the SYSTEM account.

## 2. Backups

- Written nightly to `storage/app/backups/<db>-<timestamp>.sql.gz`, 14 days kept.
- Run one on demand: `php artisan db:backup` (or `--keep=30`).
- **Copy off-box.** A backup on the same disk dies with the disk. Sync the
  `backups` folder to another drive / NAS / cloud (e.g. a second nightly task
  with `robocopy`).

### Restore a backup

```bat
REM 1. Pick a file
dir storage\app\backups
REM 2. Decompress (7-Zip, or PowerShell) and import
"C:\Program Files\7-Zip\7z.exe" e storage\app\backups\secure_exam-20260607-010000.sql.gz
C:\xampp\mysql\bin\mysql.exe -u root secure_exam < secure_exam-20260607-010000.sql
```

## 3. Incident: a token / account may be compromised

- **Leaked exam token:** open the exam → Tokens → Deactivate (or Delete). Issue
  a fresh one. Old token stops working immediately.
- **Compromised account:** deactivate the user, or reset their password
  (Students → Reset). Logging out bumps the JWT `token_version`, which
  invalidates every outstanding cookie for that user.
- **Suspected key leak (`SESSION_SECRET`):** rotate it in `.env`. This signs
  every JWT, so all sessions are invalidated and everyone re-logs-in.

## 4. Routine checks

- `php artisan app:doctor` — green before every exam day.
- Watch `storage/logs/laravel.log` (and `storage/logs/schedule.log`).
- Review the in-app **Audit log** after high-stakes exams.

## 5. Database account (least privilege)

Don't run the app as MySQL `root`. Create a scoped account once:

```bat
REM 1. edit the password inside the file, then:
C:\xampp\mysql\bin\mysql.exe -u root < database\sql\create-db-user.sql
REM 2. set DB_USERNAME=examboard + DB_PASSWORD=... in .env
php artisan config:clear && php artisan app:doctor
```

`app:doctor` warns (**DB least-privilege user**) while you're still on root.
The grant is scoped to the ExamBoard schema only — no access to other
databases and no server-admin rights.

## 6. Performance (OPcache + caches)

Two cheap, high-impact wins for the production box.

### Enable OPcache (compiles PHP once, ~2–4× faster)

Edit `C:\xampp\php\php.ini`, ensure these are set, then restart Apache:

```ini
[opcache]
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=60
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

`validate_timestamps=1` + `revalidate_freq=60` means code changes are picked up
within a minute (no manual reset). `app:doctor` reports **OPcache enabled**.

### Build the Laravel caches

After deploy and after any `.env`/config/route change:

```bat
bin\eb-optimize.cmd
```

This caches config, routes, views, and events. `app:doctor` reports **Prod
caches built** (only checked when running in production). To undo for local
editing: `php artisan optimize:clear`.

## 7. Failure alerts

By default every server error is written to `storage/logs/laravel.log`. To get
*pushed* a notification when something breaks mid-exam, set a webhook in `.env`:

```ini
ALERT_WEBHOOK_URL=https://hooks.slack.com/services/...   # or Discord/Telegram
# ALERT_EMAIL=you@example.com        # also email (needs a real MAIL_MAILER)
# ALERT_THROTTLE_MINUTES=10          # don't repeat the same alert too often
```

Alerts fire on unhandled 5xx errors and on AI job failures (generation /
grading). 4xx, validation, auth, and not-found are intentionally ignored.
`app:doctor` reports **Error alerting** once a channel is configured.
