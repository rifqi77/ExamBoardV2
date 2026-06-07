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
