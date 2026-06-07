@echo off
REM ExamBoard scheduler tick. Register this to run EVERY MINUTE in Windows
REM Task Scheduler (see docs/OPERATIONS.md). It drives nightly backups, log
REM pruning, and the scheduler heartbeat that app:doctor checks.
cd /d "%~dp0.."
php artisan schedule:run >> storage\logs\schedule.log 2>&1
